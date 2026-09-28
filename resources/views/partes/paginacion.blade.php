{{-- PAGINACIÓN estilo buscador (1 2 3 … 9 … última). La usan Estado de pedidos y Consolidado MR.
     Parámetros: $pagina, $totalPaginas, $total, $desdeFila, $hastaFila, $etiqueta ("pedidos",
     "renglones"), $urlPagina (función: número de página → URL con los filtros actuales). --}}
<div class="paginacion">
    <span class="paginacion-info">
        {{ fmtMil($desdeFila) }}–{{ fmtMil($hastaFila) }}
        de <strong>{{ fmtMil($total) }}</strong> {{ $etiqueta }}
    </span>
    @if ($totalPaginas > 1)
        <nav class="paginacion-botones" aria-label="Paginación">
            @if ($pagina > 1)
                <a class="pagina-flecha" href="{{ $urlPagina($pagina - 1) }}" rel="prev" aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></a>
            @else
                <span class="pagina-flecha deshabilitada" aria-hidden="true"><i class="fa-solid fa-chevron-left"></i></span>
            @endif

            @foreach (numerosDePagina($pagina, $totalPaginas) as $n)
                @if ($n === 0)
                    <span class="pagina-elipsis">…</span>
                @elseif ($n === $pagina)
                    <span class="pagina-num activa" aria-current="page">{{ $n }}</span>
                @else
                    <a class="pagina-num" href="{{ $urlPagina($n) }}">{{ $n }}</a>
                @endif
            @endforeach

            @if ($pagina < $totalPaginas)
                <a class="pagina-flecha" href="{{ $urlPagina($pagina + 1) }}" rel="next" aria-label="Página siguiente"><i class="fa-solid fa-chevron-right"></i></a>
            @else
                <span class="pagina-flecha deshabilitada" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
            @endif
        </nav>
    @endif
</div>
