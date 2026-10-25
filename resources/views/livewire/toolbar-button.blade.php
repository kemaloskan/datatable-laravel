{{--
  The content of a toolbar button (columns, filters, tools): its icon and
  text, as toolbarButton() resolves them. The text stays for screen readers
  when only the icon shows.
--}}
@if ($button['path'] !== null)<svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $button['path'] }}"/></svg>@endif
<span class="adt-summary-text">{{ $button['label'] }}</span>
