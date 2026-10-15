<?php

// Fase 8.3 (15-oct-2026): sin este archivo el inicio de sesión mostraba la
// clave cruda "auth.failed" en vez del mensaje (locale y fallback = es).
return [
    'failed' => 'El correo o la contraseña no son correctos. Revisa los datos e inténtalo de nuevo.',
    'password' => 'La contraseña no es correcta.',
    'throttle' => 'Demasiados intentos de inicio de sesión. Espera :seconds segundos e inténtalo de nuevo.',
];
