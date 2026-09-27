<!DOCTYPE html>
<html lang="zh-Hant-TW">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name') }}｜管理後台</title>
        @vite(['resources/css/app.css', 'resources/js/admin/main.js'])
    </head>
    <body class="bg-gray-100 text-gray-900 antialiased">
        <div id="admin-app"></div>
    </body>
</html>
