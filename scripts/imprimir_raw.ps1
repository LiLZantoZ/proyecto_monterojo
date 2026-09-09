# scripts/imprimir_raw.ps1
# Manda un archivo TAL CUAL a una impresora, sin que el driver lo interprete ni lo dibuje.
#
# Por que hace falta: la TSC TE200 no espera una pagina, espera comandos TSPL ("SIZE 100 mm, 40 mm",
# "BARCODE ...", "PRINT 1,1"). Si el trabajo se manda por la via normal de Windows, el driver los
# trata como TEXTO y termina imprimiendo las letras del comando en la etiqueta. La unica forma de
# que lleguen como comandos es abrir el trabajo con el tipo de datos "RAW", que es lo que hace este
# script llamando directo al spooler de Windows (winspool.drv).
#
# Se usa desde PHP (ver modules/historial/helper_rotulos_tspl.php):
#   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\imprimir_raw.ps1 `
#              -Impresora "TSC TE200" -Archivo "C:\ruta\rotulos.prn"
#
# Escribe "OK <bytes>" y sale con codigo 0 si el trabajo entro a la cola; cualquier otra cosa en
# stderr con codigo 1. El PHP se fija en el codigo de salida.

param(
    [Parameter(Mandatory = $true)] [string] $Impresora,
    [Parameter(Mandatory = $true)] [string] $Archivo
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $Archivo)) {
    Write-Error "No existe el archivo a imprimir: $Archivo"
    exit 1
}

Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

public class SpoolerCrudo
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public class DOCINFO
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
    }

    [DllImport("winspool.Drv", EntryPoint = "OpenPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool OpenPrinter(string nombre, out IntPtr handle, IntPtr valores);

    [DllImport("winspool.Drv", EntryPoint = "ClosePrinter", SetLastError = true)]
    static extern bool ClosePrinter(IntPtr handle);

    [DllImport("winspool.Drv", EntryPoint = "StartDocPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool StartDocPrinter(IntPtr handle, int nivel, [In, MarshalAs(UnmanagedType.LPStruct)] DOCINFO info);

    [DllImport("winspool.Drv", EntryPoint = "EndDocPrinter", SetLastError = true)]
    static extern bool EndDocPrinter(IntPtr handle);

    [DllImport("winspool.Drv", EntryPoint = "StartPagePrinter", SetLastError = true)]
    static extern bool StartPagePrinter(IntPtr handle);

    [DllImport("winspool.Drv", EntryPoint = "EndPagePrinter", SetLastError = true)]
    static extern bool EndPagePrinter(IntPtr handle);

    [DllImport("winspool.Drv", EntryPoint = "WritePrinter", SetLastError = true)]
    static extern bool WritePrinter(IntPtr handle, IntPtr datos, int cantidad, out int escritos);

    public static int Enviar(string impresora, byte[] datos, string titulo)
    {
        IntPtr handle;
        if (!OpenPrinter(impresora, out handle, IntPtr.Zero))
            throw new Exception("No se pudo abrir la impresora '" + impresora + "' (codigo " + Marshal.GetLastWin32Error() + ")");

        try
        {
            DOCINFO info = new DOCINFO();
            info.pDocName  = titulo;
            info.pDataType = "RAW";      // <- la clave: el spooler no toca los bytes

            if (!StartDocPrinter(handle, 1, info))
                throw new Exception("StartDocPrinter fallo (codigo " + Marshal.GetLastWin32Error() + ")");

            try
            {
                if (!StartPagePrinter(handle))
                    throw new Exception("StartPagePrinter fallo (codigo " + Marshal.GetLastWin32Error() + ")");

                IntPtr buffer = Marshal.AllocCoTaskMem(datos.Length);
                try
                {
                    Marshal.Copy(datos, 0, buffer, datos.Length);
                    int escritos;
                    if (!WritePrinter(handle, buffer, datos.Length, out escritos))
                        throw new Exception("WritePrinter fallo (codigo " + Marshal.GetLastWin32Error() + ")");
                    EndPagePrinter(handle);
                    return escritos;
                }
                finally { Marshal.FreeCoTaskMem(buffer); }
            }
            finally { EndDocPrinter(handle); }
        }
        finally { ClosePrinter(handle); }
    }
}
'@

try {
    $bytes = [System.IO.File]::ReadAllBytes((Resolve-Path -LiteralPath $Archivo))
    $escritos = [SpoolerCrudo]::Enviar($Impresora, $bytes, "Rotulos Monterojo")
    Write-Output "OK $escritos"
    exit 0
}
catch {
    Write-Error $_.Exception.Message
    exit 1
}
