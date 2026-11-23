import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Une clases condicionales (clsx) y resuelve conflictos de Tailwind (tailwind-merge). */
export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/** Devuelve la URL de un href de Inertia: string tal cual o `.url` de una ruta Wayfinder. */
export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}
