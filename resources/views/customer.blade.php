<!DOCTYPE html>
<html lang="zh-Hant-TW" class="customer-root">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#1D4E89">
        <title>{{ config('shop.name') }}｜線上叫貨</title>
        <script>
            window.__SHOP__ = @json(['name' => config('shop.name'), 'phone' => config('shop.phone')]);
        </script>
        @vite(['resources/css/app.css', 'resources/js/customer/main.js'])
    </head>
    <body class="antialiased">
        <div id="customer-app"></div>
    </body>
</html>
