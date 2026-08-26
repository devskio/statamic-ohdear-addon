@php
    $isFailed = $status === 'failed';
    $isWarning = $status === 'warning';

    $badgeLabel = $failedCount > 0
        ? $failedCount.' failed'
        : ($warningCount > 0 ? $warningCount.' warning'.($warningCount === 1 ? '' : 's') : 'All clear');

    $hasChecks = ! empty($monitors);
@endphp

<div class="ohd">
    @if (! $hasChecks)
        <div class="ohd-empty">
            <div class="ohd-empty__main">
                <span class="ohd-kicker">Oh Dear</span>
                <h2 class="ohd-empty__title">Not connected</h2>
                <span class="ohd-empty__body">
                    No health checks are running yet. Configure the checks for <strong>{{ $site }}</strong> to pull
                    disk space, error log, storage size and forgotten files into this widget.
                </span>
            </div>
            <div class="ohd-empty__actions">
                <a href="{{ $configUrl }}" class="ohd-btn ohd-btn--primary">Configure checks</a>
                <a href="{{ $reportUrl }}" target="_blank" rel="noopener" class="ohd-btn ohd-btn--secondary">Open report</a>
            </div>
        </div>
    @else
        <div class="ohd-card {{ $isFailed ? 'ohd-card--failed' : '' }}">
            {{-- Header --}}
            <div class="ohd-head {{ $isFailed ? 'ohd-head--failed' : '' }}">
                <div class="ohd-head__main">
                    <div class="ohd-head__meta">
                        <span class="ohd-kicker">Oh Dear</span>
                        <span class="ohd-rule"></span>
                        <span class="ohd-site">{{ $site }}</span>
                    </div>
                    <h2 class="ohd-headline {{ $isWarning ? 'ohd-headline--warning' : '' }}">{{ $headline }}</h2>
                </div>

                <div class="ohd-head__aside">
                    <div class="ohd-score">
                        <span class="ohd-score__value">{{ $passing }} / {{ $total }}</span>
                        <span class="ohd-score__label">Checks passing</span>
                    </div>

                    <span class="ohd-tag {{ $isFailed ? 'ohd-tag--inverse' : ($warningCount > 0 ? 'ohd-tag--accent' : 'ohd-tag--outline') }}">
                        {{ $badgeLabel }}
                    </span>

                    <span class="ohd-note">
                        @if ($finishedAt)
                            Checked {{ \Carbon\Carbon::parse($finishedAt)->diffForHumans() }}
                        @else
                            Not checked yet
                        @endif
                    </span>

                    <a href="{{ $refreshUrl }}" class="ohd-link">Refresh</a>
                    <a href="{{ $reportUrl }}" target="_blank" rel="noopener" class="ohd-link">Open report</a>
                </div>
            </div>

            {{-- Monitors --}}
            @unless ($isFailed)
                <div class="ohd-section ohd-section--tight">
                    <span class="ohd-kicker">Monitors</span>
                </div>
            @endunless

            <div class="ohd-grid ohd-grid--monitors">
                @foreach ($monitors as $monitor)
                    <a href="{{ $monitor['url'] }}" class="ohd-monitor">
                        <span class="ohd-monitor__name">{{ $monitor['name'] }}</span>
                        <span class="ohd-monitor__value">{{ $monitor['value'] }}</span>
                        <span class="ohd-monitor__note {{ $monitor['bad'] ? 'ohd-monitor__note--bad' : '' }}" title="{{ $monitor['note'] }}">
                            {{ $monitor['note'] }}
                        </span>
                    </a>
                @endforeach
            </div>

            {{-- Application health --}}
            <div class="ohd-section">
                <span class="ohd-kicker">Application health</span>
                <span class="ohd-note">reported by the Statamic addon</span>
            </div>

            <div class="ohd-grid ohd-grid--cards">
                @foreach ($applicationHealth as $card)
                    <a href="{{ $card['url'] }}" class="ohd-metric {{ $card['bad'] ? 'ohd-metric--bad' : '' }}">
                        <span class="ohd-metric__label">{{ $card['label'] }}</span>

                        <span class="ohd-metric__value">{{ $card['value'] }}@if ($card['valueSuffix'])<span class="ohd-metric__unit">{{ $card['valueSuffix'] }}</span>@endif</span>

                        @if ($card['bar'] !== null)
                            <div class="ohd-bar">
                                <div class="ohd-bar__fill" style="width: {{ min(100, max(0, (float) $card['bar'])) }}%;"></div>
                            </div>
                        @endif

                        <span class="ohd-metric__detail">{{ $card['detail'] }}</span>

                        @if (! empty($card['items']))
                            <ul class="ohd-items {{ $card['bad'] ? 'ohd-items--bad' : '' }}">
                                @foreach ($card['items'] as $item)
                                    <li title="{{ $item }}">{{ $item }}</li>
                                @endforeach
                            </ul>
                            @if ($card['linkLabel'])
                                <span class="ohd-more">{{ $card['linkLabel'] }}</span>
                            @endif
                        @endif

                        @if ($card['threshold'])
                            <span class="ohd-metric__threshold">{{ $card['threshold'] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            {{-- Footer --}}
            <div class="ohd-foot">
                <span class="ohd-foot__text">
                    @if ($cacheSeconds > 0)
                        Cached up to {{ $cacheSeconds }}s · thresholds set in config
                    @else
                        Live results · thresholds set in config
                    @endif
                </span>
                <a href="{{ $reportUrl }}" target="_blank" rel="noopener" class="ohd-link">Open report</a>
                <a href="{{ $configUrl }}" class="ohd-link">Configure</a>
            </div>
        </div>
    @endif
</div>
