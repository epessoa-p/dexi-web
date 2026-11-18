<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', \App\Support\Brand::appName())</title>
    @include('layouts.partials.favicons')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --brand-lightest: {{ config('brand.colors.lightest') }};
            --brand-light: {{ config('brand.colors.light') }};
            --brand-base: {{ config('brand.colors.base') }};
            --brand-dark: {{ config('brand.colors.dark') }};
            --brand-darkest: {{ config('brand.colors.darkest') }};
        }

        html, body {
            margin: 0;
            padding: 0;
        }

        /* Misma escala compacta que el panel (public/css/dexi.css). */
        html { font-size: 14px; }
        @media (min-width: 1600px) { html { font-size: 15px; } }

        body {
            background: linear-gradient(135deg, var(--brand-base) 0%, var(--brand-darkest) 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            /* El alto real de la ventana (sin las barras del navegador): sin scroll si el formulario cabe. */
            min-height: 100vh;
            min-height: 100dvh;
        }

        .auth-container {
            width: 100%;
            max-width: 380px;
            padding: 1rem;
        }

        .auth-card {
            background: white;
            border-radius: .85rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 1.75rem 1.9rem;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 1.25rem;
        }

        /* Logo completo de Dexi (diente + «dexi»). */
        .auth-logo {
            display: block;
            width: 88px;
            height: auto;
            margin: 0 auto .5rem;
        }

        .auth-icon {
            width: 80px;
            height: 80px;
            background: #fff;
            border: 1px solid #e3eaec;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            margin: 0 auto 20px;
            box-shadow: 0 8px 22px rgba(14, 159, 154, 0.18);
        }

        .tooth-icon { vertical-align: -0.125em; }

        .auth-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        .auth-header p {
            color: #999;
            font-size: .85rem;
            margin: 0;
        }

        .form-control {
            padding: .5rem .75rem;
            border: 1px solid #ddd;
            border-radius: .4rem;
            font-size: .925rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--brand-light);
            box-shadow: 0 0 0 0.2rem rgba(95, 169, 122, 0.25);
        }

        .form-group {
            margin-bottom: .9rem;
        }

        .form-label {
            color: #555;
            font-weight: 600;
            margin-bottom: .3rem;
            font-size: .85rem;
        }

        .btn-login {
            background: linear-gradient(135deg, var(--brand-base) 0%, var(--brand-dark) 100%);
            border: none;
            color: white;
            padding: .6rem 1rem;
            border-radius: .4rem;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
            margin-top: .25rem;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(63, 132, 89, 0.4);
            color: white;
        }

        .alert {
            border: none;
            border-radius: .4rem;
            margin-bottom: 1rem;
            padding: .6rem .8rem;
            font-size: .875rem;
        }

        .input-group-text {
            border: 1px solid #ddd;
            background-color: #f9f9f9;
        }

        .remember-me {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            color: #666;
            font-size: .85rem;
        }

        .remember-me input {
            margin-right: 8px;
            cursor: pointer;
        }

        .forgot-password {
            text-align: right;
            margin-top: -10px;
            margin-bottom: 20px;
        }

        .forgot-password a {
            color: var(--brand-dark);
            text-decoration: none;
            font-size: 0.9rem;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        /* Firma del desarrollador */
        .app-signature {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.72rem;
        }

        .app-signature-vendor {
            display: inline-flex;
            align-items: center;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            letter-spacing: 0.06em;
        }

        .app-signature-logo {
            height: 20px;
            width: auto;
            display: block;
        }

        @media (max-width: 480px) {
            .auth-card {
                padding: 1.4rem 1.25rem;
            }

            .auth-header h1 {
                font-size: 1.5rem;
            }

            .auth-icon {
                width: 64px;
                height: 64px;
                border-radius: 20px;
            }
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="auth-container">
        @yield('content')

        @include('partials.signature', ['class' => 'mt-3'])
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
