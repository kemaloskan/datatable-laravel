<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Workbench · {{ class_basename($component) }}</title>
<link rel="stylesheet" href="/styles.css">
<link rel="stylesheet" href="/import/styles.css">
<script src="/import/acun-datatable-import.min.js"></script>
<style>body { font-family: system-ui, sans-serif; margin: 0; padding: 24px 16px; background: #f5f6f8; }</style>
@livewireStyles
</head>
<body>
@livewire($component, $params)
@livewireScripts
</body>
</html>
