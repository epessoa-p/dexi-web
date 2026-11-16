<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="{{ config('brand.colors.base') }}">
    <title>@yield('title', \App\Support\Brand::appName()) · {{ \App\Support\Brand::appName() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --brand-lightest: {{ config('brand.colors.lightest') }};
            --brand-light: {{ config('brand.colors.light') }};
            --brand-base: {{ config('brand.colors.base') }};
            --brand-dark: {{ config('brand.colors.dark') }};
            --brand-darkest: {{ config('brand.colors.darkest') }};
            --brand-rgb: {{ config('brand.colors.rgb', '14, 159, 154') }};

            --bs-primary: var(--brand-base);
            --bs-primary-rgb: var(--brand-rgb);
            --bs-link-color: var(--brand-dark);
            --bs-link-hover-color: var(--brand-darkest);
        }
    </style>
    <link rel="stylesheet" href="{{ asset('css/dexi.css') }}?v={{ @filemtime(public_path('css/dexi.css')) }}">
    @stack('styles')
</head>
<body>
    @yield('content')

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    @stack('scripts')
</body>
</html>
