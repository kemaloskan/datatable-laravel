{{--
  "Import…" with importEndpoint(): opens the import dialog of
  @acunsoft/datatable-import (load acun-datatable-import.min.js and its
  styles.css on the page) for the TableImport at $url, in the app's language,
  with the CSRF token; the table is refreshed when rows changed. Printed
  inside an attribute, so the script uses single quotes only.
--}}
(() => { if (! window.AcunImport) { console.error('Acun DataTable: load acun-datatable-import.min.js to use importEndpoint().'); return; } window.AcunImport.openImport({ url: {{ \Illuminate\Support\Js::from($url) }}, language: {{ \Illuminate\Support\Js::from(app()->getLocale()) }}, headers: {{ \Illuminate\Support\Js::from((object) array_filter(['X-CSRF-TOKEN' => csrf_token()])) }}, table: { reload: () => $wire.$refresh() } }); })()
