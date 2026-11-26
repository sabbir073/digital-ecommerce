@php $columns = $d['table']['columns']; $rows = $d['table']['rows']; @endphp
@if ($columns && $rows)
    @include('selling.blocks._open')
        <div class="container-selling">
            @if ($d['heading'])
                <h2 class="section-heading" @edit('heading')>{{ $d['heading'] }}</h2>
            @endif
            {{-- Wide tables scroll inside their own box instead of stretching the page. --}}
            <div class="sp-table-wrap mt-6" tabindex="0" role="region" aria-label="{{ $d['heading'] ?: 'Comparison table' }}">
                <table class="sp-table">
                    <thead>
                        <tr>
                            @foreach ($columns as $column)
                                <th scope="col">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                @foreach ($row as $index => $cell)
                                    @if ($index === 0)
                                        <th scope="row">{{ $cell }}</th>
                                    @else
                                        <td>{{ $cell }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endif
