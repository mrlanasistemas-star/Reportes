<?php

/**
 * Las pruebas nunca tocan storage/ real: storage_path() y el disco local/public
 * apuntan al sandbox de pruebas.
 */
it('usa un almacenamiento aislado y nunca el storage real', function () {
    $sandbox = str_replace('\\', '/', base_path('storage/framework/testing/sandbox'));

    expect(str_replace('\\', '/', storage_path()))->toBe($sandbox)
        ->and(str_replace('\\', '/', config('filesystems.disks.local.root')))->toStartWith($sandbox)
        ->and(str_replace('\\', '/', config('filesystems.disks.public.root')))->toStartWith($sandbox)
        ->and(str_replace('\\', '/', storage_path('app/private/efectividad-tmp')))->toStartWith($sandbox);
});
