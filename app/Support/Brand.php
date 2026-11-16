<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resuelve la identidad visual del sistema combinando config/brand.php con
 * lo que el super admin haya configurado en Configuración global.
 */
class Brand
{
    public static function appName(): string
    {
        return settings()->get('saas.app_name', config('brand.name'), null)
            ?: config('brand.name');
    }

    public static function tagline(): string
    {
        return settings()->get('saas.tagline', config('brand.tagline'), null)
            ?: config('brand.tagline');
    }

    public static function vendorName(): string
    {
        return config('brand.vendor');
    }

    public static function vendorUrl(): ?string
    {
        return config('brand.vendor_url');
    }

    /**
     * Logo del desarrollador (SCZ SOFT). null si el archivo aún no fue colocado
     * en public/, para que las vistas caigan a la firma en texto.
     */
    public static function vendorLogoUrl(): ?string
    {
        $path = config('brand.vendor_logo');

        return $path && file_exists(public_path($path)) ? asset($path) : null;
    }

    /**
     * Logo a usar en modo global: el que subió el super admin o, en su
     * defecto, el del desarrollador.
     */
    public static function globalLogoUrl(): ?string
    {
        $uploaded = settings()->get('saas.logo', null, null);

        return static::resolveUrl($uploaded) ?? static::vendorLogoUrl();
    }

    public static function supportEmail(): ?string
    {
        return settings()->get('saas.support_email', null, null);
    }

    public static function supportPhone(): ?string
    {
        return settings()->get('saas.support_phone', null, null);
    }

    /**
     * Convierte una ruta guardada en configuración a URL pública.
     */
    public static function resolveUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            return $path;
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return file_exists(public_path($path)) ? asset($path) : null;
    }
}
