@php
    /**
     * Template do relatório financeiro em PDF.
     *
     * Escrito para o dompdf, não para navegador: sem flexbox e sem grid — o
     * motor não os suporta e o layout colapsaria silenciosamente. Por isso a
     * estrutura usa tabelas, que ele renderiza de forma previsível.
     */
    $money = static fn (int $cents): string => 'R$ '.number_format($cents / 100, 2, ',', '.');

    $methodLabels = [
        'cash' => 'Dinheiro na entrega',
        'card_on_delivery' => 'Cartão na entrega',
        'pix_on_delivery' => 'Pix na entrega',
        'stripe_card' => 'Cartão pelo site',
        'stripe_pix' => 'Pix pelo site',
    ];

    $maxRevenue = collect($monthly)->max('revenueCents') ?: 1;
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28mm 14mm 20mm 14mm; }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 9pt;
        color: #1f2937;
        margin: 0;
    }

    /* Cabeçalho e rodapé fixos em toda página: num relatório de várias folhas,
       cada uma precisa se identificar sozinha se for impressa avulsa. */
    header {
        position: fixed;
        top: -18mm; left: 0; right: 0;
        height: 14mm;
        border-bottom: 0.6pt solid #d4d4d8;
    }

    footer {
        position: fixed;
        bottom: -12mm; left: 0; right: 0;
        height: 8mm;
        font-size: 7.5pt;
        color: #71717a;
        border-top: 0.6pt solid #e4e4e7;
        padding-top: 2mm;
    }

    .page-number:after { content: counter(page); }

    h1 { font-size: 15pt; margin: 0 0 1mm; }
    h2 { font-size: 10pt; margin: 0 0 2.5mm; color: #3f3f46; }

    .muted { color: #71717a; }
    .right { text-align: right; }

    table { width: 100%; border-collapse: collapse; }

    .cards td {
        width: 25%;
        border: 0.6pt solid #e4e4e7;
        border-radius: 2mm;
        padding: 3mm;
        vertical-align: top;
    }
    .card-label { font-size: 7.5pt; color: #71717a; text-transform: uppercase; }
    .card-value { font-size: 13pt; font-weight: bold; padding-top: 1mm; }

    .data th {
        background: #f4f4f5;
        font-size: 7.5pt;
        text-transform: uppercase;
        color: #52525b;
        text-align: left;
        padding: 2mm;
        border-bottom: 0.6pt solid #d4d4d8;
    }
    .data td {
        padding: 1.8mm 2mm;
        border-bottom: 0.4pt solid #efeff1;
        font-size: 8.5pt;
    }
    /* Repete o cabeçalho da tabela quando ela quebra de página. */
    .data thead { display: table-header-group; }
    .data tr { page-break-inside: avoid; }

    .section { margin-top: 7mm; }
    .totals td { font-weight: bold; border-top: 0.8pt solid #a1a1aa; background: #fafafa; }
</style>
</head>
<body>

<header>
    <table>
        <tr>
            <td style="vertical-align: middle;">
                @if ($logoPath)
                    <img src="{{ $logoPath }}" style="max-height: 9mm; max-width: 40mm;" alt="">
                @else
                    <strong style="font-size: 11pt;">{{ $tenant->name }}</strong>
                @endif
            </td>
            <td class="right muted" style="vertical-align: middle; font-size: 8pt;">
                Relatório financeiro<br>
                {{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}
            </td>
        </tr>
    </table>
</header>

<footer>
    <table>
        <tr>
            <td>{{ $tenant->name }} · gerado em {{ $generatedAt->format('d/m/Y H:i') }}</td>
            <td class="right">Página <span class="page-number"></span></td>
        </tr>
    </table>
</footer>

<main>
    <h1>Relatório financeiro</h1>
    <p class="muted" style="margin: 0 0 5mm; font-size: 8.5pt;">
        Período de {{ $from->format('d/m/Y') }} a {{ $to->format('d/m/Y') }}.
        Considera pedidos entregues e pagos.
        @if ($search !== '')
            Filtrado por “{{ $search }}”.
        @endif
    </p>

    <table class="cards">
        <tr>
            <td>
                <div class="card-label">Receita total</div>
                <div class="card-value">{{ $money($summary['revenueCents']) }}</div>
            </td>
            <td>
                <div class="card-label">Pedidos</div>
                <div class="card-value">{{ number_format($summary['ordersCount'], 0, ',', '.') }}</div>
            </td>
            <td>
                <div class="card-label">Ticket médio</div>
                <div class="card-value">{{ $money($summary['averageTicketCents']) }}</div>
            </td>
            <td>
                <div class="card-label">Taxas de entrega</div>
                <div class="card-value">{{ $money($summary['deliveryCents']) }}</div>
            </td>
        </tr>
    </table>

    @if (count($monthly) > 1)
        <div class="section">
            <h2>Receita por mês</h2>
            {{-- Barras desenhadas com <div>: o dompdf tem suporte irregular a
                 SVG, e retângulos posicionados sempre saem corretos. --}}
            <table>
                @foreach ($monthly as $month)
                    @php
                        $ratio = $maxRevenue > 0 ? $month['revenueCents'] / $maxRevenue : 0;
                        $width = max(0.4, $ratio * 100);
                    @endphp
                    <tr>
                        <td style="width: 16mm; font-size: 8pt; padding: 1mm 0;">{{ $month['label'] }}</td>
                        <td style="padding: 1mm 0;">
                            <div style="background: #e07a5f; height: 3.2mm; width: {{ number_format($width, 2, '.', '') }}%;"></div>
                        </td>
                        <td class="right" style="width: 28mm; font-size: 8pt; padding: 1mm 0;">
                            {{ $money($month['revenueCents']) }}
                        </td>
                        <td class="right muted" style="width: 16mm; font-size: 8pt; padding: 1mm 0;">
                            {{ $month['ordersCount'] }} ped.
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if (count($byMethod) > 0)
        <div class="section">
            <h2>Por forma de pagamento</h2>
            <table class="data">
                <thead>
                    <tr>
                        <th>Forma</th>
                        <th class="right">Pedidos</th>
                        <th class="right">Receita</th>
                        <th class="right">% do total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byMethod as $item)
                        <tr>
                            <td>{{ $methodLabels[$item['method']] ?? $item['method'] }}</td>
                            <td class="right">{{ $item['ordersCount'] }}</td>
                            <td class="right">{{ $money($item['revenueCents']) }}</td>
                            <td class="right">
                                {{ $summary['revenueCents'] > 0
                                    ? number_format($item['revenueCents'] / $summary['revenueCents'] * 100, 1, ',', '.')
                                    : '0,0' }}%
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="section">
        <h2>Pedidos do período ({{ count($rows) }})</h2>

        @if (count($rows) === 0)
            <p class="muted" style="font-size: 8.5pt;">
                Nenhum pedido entregue e pago neste período.
            </p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th>Pagamento</th>
                        <th class="right">Subtotal</th>
                        <th class="right">Entrega</th>
                        <th class="right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['number'] }}</td>
                            <td>{{ $row['date'] }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($row['customer'], 26) }}</td>
                            <td>{{ $methodLabels[$row['method']] ?? $row['method'] }}</td>
                            <td class="right">{{ $money($row['subtotal']) }}</td>
                            <td class="right">{{ $money($row['delivery']) }}</td>
                            <td class="right">{{ $money($row['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="totals">
                        <td colspan="4">Total do período</td>
                        <td class="right">{{ $money($summary['subtotalCents']) }}</td>
                        <td class="right">{{ $money($summary['deliveryCents']) }}</td>
                        <td class="right">{{ $money($summary['revenueCents']) }}</td>
                    </tr>
                </tbody>
            </table>
        @endif
    </div>
</main>

</body>
</html>
