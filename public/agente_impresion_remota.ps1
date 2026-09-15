# scripts/agente_impresion_remota.ps1
# EL AGENTE DE IMPRESIÓN REMOTA. Corre en la PC donde está conectada la etiquetadora por USB,
# NO en el servidor.
#
# QUÉ HACE: cada pocos segundos le pregunta al servidor "¿hay algo para imprimir?" (un pedido
# HACIA AFUERA, que nunca necesita permisos de administrador en Windows). Si hay algo, lo imprime
# en la impresora LOCAL de esta PC —la misma técnica RAW de scripts/imprimir_raw.ps1, para que la
# etiqueta salga igual de bien que si el servidor la mandara directo— y le avisa al servidor si
# pudo o no.
#
# POR QUÉ EXISTE: la impresora está en esta PC y no en el servidor, y no se pudo compartir por
# Windows (hace falta ser administrador de esta PC y no se cuenta con eso). Así, ni esta PC ni el
# servidor necesitan ningún permiso especial: esta PC solo pide datos, y solo le habla a SU PROPIA
# impresora, ya instalada.
#
# CÓMO USARLO:
#   powershell -NoProfile -ExecutionPolicy Bypass -File agente_impresion_remota.ps1 `
#              -Servidor "http://10.7.12.149/proyecto_monterojo" `
#              -Token "EL_TOKEN_DE_config.php" `
#              -Impresora "TSC TA210"
#
# Se deja la ventana abierta (Ctrl+C para cortar). Para que arranque solo al prender la PC, ver las
# instrucciones que acompañan este archivo.

param(
    [Parameter(Mandatory = $true)] [string] $Servidor,
    [Parameter(Mandatory = $true)] [string] $Token,
    [string] $Impresora = 'TSC TA210',
    [int]    $IntervaloSegundos = 3
)

$ErrorActionPreference = 'Stop'
$Servidor = $Servidor.TrimEnd('/')

# ------------------------------------------------------------------------------------------------
# LA MISMA CLASE de scripts/imprimir_raw.ps1: manda los bytes al spooler de Windows con tipo de
# datos "RAW", para que el driver los pase tal cual a la impresora en vez de tratarlos como texto
# e imprimir las LETRAS de los comandos TSPL. Va copiada acá (y no llamando al otro script) porque
# este archivo tiene que poder vivir solo, copiado a una PC que no tiene el resto del proyecto.
# ------------------------------------------------------------------------------------------------
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

public class SpoolerCrudoAgente
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
            info.pDataType = "RAW";

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

function Escribir-Log([string] $texto) {
    Write-Host ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $texto)
}

Escribir-Log "Agente de impresión remota iniciado. Servidor: $Servidor · Impresora: $Impresora"
Escribir-Log "Consultando cada $IntervaloSegundos segundo(s). Ctrl+C para cortar."

while ($true) {
    try {
        $url = "$Servidor/rotulos/agente?accion=siguiente&token=$Token"
        $resp = Invoke-WebRequest -Uri $url -Method GET -UseBasicParsing -TimeoutSec 15

        if ($resp.StatusCode -eq 200) {
            # RawContentStream: los bytes TAL CUAL llegaron, sin que PowerShell intente
            # adivinar si es texto (que es lo que haría con .Content y rompería el bitmap del logo).
            $bytes     = $resp.RawContentStream.ToArray()
            $idTrabajo = $resp.Headers['X-Trabajo-Id']
            $etiquetas = $resp.Headers['X-Etiquetas']

            Escribir-Log "Trabajo #$idTrabajo recibido ($etiquetas etiqueta(s), $($bytes.Length) bytes). Imprimiendo..."

            try {
                $escritos = [SpoolerCrudoAgente]::Enviar($Impresora, $bytes, "Rotulos Monterojo (remoto)")
                Escribir-Log "Trabajo #$idTrabajo enviado a $Impresora ($escritos bytes)."

                Invoke-WebRequest -Uri "$Servidor/rotulos/agente?accion=confirmar" -Method POST -UseBasicParsing -TimeoutSec 15 `
                    -Body @{ token = $Token; id_trabajo = $idTrabajo; resultado = 'ok' } | Out-Null
            }
            catch {
                $error = $_.Exception.Message
                Escribir-Log "ERROR imprimiendo el trabajo #${idTrabajo}: $error"

                try {
                    Invoke-WebRequest -Uri "$Servidor/rotulos/agente?accion=confirmar" -Method POST -UseBasicParsing -TimeoutSec 15 `
                        -Body @{ token = $Token; id_trabajo = $idTrabajo; resultado = 'error'; mensaje = $error } | Out-Null
                } catch { }
            }

            # Había un trabajo: no se espera el intervalo completo, por si hay más en la cola.
            continue
        }
    }
    catch {
        Escribir-Log "No se pudo hablar con el servidor: $($_.Exception.Message)"
    }

    Start-Sleep -Seconds $IntervaloSegundos
}
