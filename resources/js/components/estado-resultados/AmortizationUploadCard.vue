<script setup lang="ts">
/**
 * AmortizationUploadCard — carga de la tabla de amortización de un crédito.
 *
 * La usa CreditStatusCard (sección «Archivo»). Muestra la tabla vigente (nombre,
 * pagos, vigencia, quién la subió), sus avisos y la bitácora; permite elegir si el
 * documento es «Simulación» o «Definitivo», descargar la plantilla y subir o
 * sustituir la tabla.
 *
 * Props:
 * - credit: datos del crédito en el periodo (tabla vigente e historial).
 * - accept: extensiones aceptadas por el input de archivo.
 * - canUpload: si el usuario puede subir la tabla.
 * - uploading: si la tabla se está subiendo ahora.
 *
 * Emits:
 * - upload(file, kind): el usuario eligió un archivo de tabla y su tipo de documento.
 * - template(): descargar la plantilla de la tabla.
 */
import {
    AlertTriangle,
    Download,
    FileSpreadsheet,
    History,
    Loader2,
    Upload,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { dmy } from './financialExpenses';
import type { CreditPeriod, DocumentKind } from './financialExpenses';
import FinInfoTip from './FinInfoTip.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

const props = defineProps<{
    credit: CreditPeriod;
    accept: string;
    canUpload: boolean;
    uploading: boolean;
}>();

const emit = defineEmits<{
    upload: [file: File, kind: DocumentKind];
    template: [];
}>();

/** Referencia al input de archivo oculto. */
const input = ref<HTMLInputElement | null>(null);
/** Si la bitácora de cargas está desplegada. */
const showHistory = ref(false);
/** Carga vigente de la tabla, o null si aún no hay. */
const up = computed(() => props.credit.upload);
/** Tipo de documento que se enviará con la próxima carga. */
const kind = ref<DocumentKind>(up.value?.document_kind ?? 'simulacion');

const KINDS: Array<{ key: DocumentKind; label: string }> = [
    { key: 'simulacion', label: 'Simulación' },
    { key: 'definitivo', label: 'Definitivo' },
];

/** Abre el selector de archivos del input oculto. */
function pick(): void {
    input.value?.click();
}

/**
 * Emite `upload` con el archivo elegido y el tipo de documento; limpia el input
 * para poder volver a elegir el mismo archivo.
 * @param e evento change del input.
 */
function onFile(e: Event): void {
    const el = e.target as HTMLInputElement;
    const file = el.files?.[0];

    if (file) {
        emit('upload', file, kind.value);
    }

    el.value = '';
}
</script>

<template>
    <div class="space-y-3 pt-1">
        <div
            v-if="up"
            class="flex min-w-0 items-center gap-2 rounded-xl bg-muted/40 p-2.5 text-xs"
        >
            <FileSpreadsheet
                class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
            />
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold" :title="up.name">
                    {{ up.name }}
                </p>
                <p class="text-[11px] text-muted-foreground tabular-nums">
                    {{ up.rows }} pagos · {{ dmy(up.first_date) }} –
                    {{ dmy(up.last_date) }}
                </p>
            </div>
            <FinInfoTip
                :text="`Subida por ${up.uploaded_by ?? '—'} · ${up.uploaded_at ?? ''}\nHuella ${up.hash}`"
                label="Datos de la carga"
            />
        </div>
        <p v-else class="flex items-center gap-2 text-xs text-muted-foreground">
            <FileSpreadsheet class="size-4" /> Aún no hay tabla cargada.
        </p>

        <p
            v-if="up?.warnings.length"
            class="flex items-start gap-1.5 text-[11px] font-medium text-amber-800 dark:text-amber-300"
        >
            <AlertTriangle class="mt-px size-3.5 shrink-0" />
            <span class="line-clamp-2" :title="up.warnings.join(' ')">{{
                up.warnings.join(' ')
            }}</span>
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <div
                v-if="canUpload"
                role="radiogroup"
                aria-label="Tipo de documento"
                class="inline-flex rounded-lg border p-0.5"
            >
                <button
                    v-for="k in KINDS"
                    :key="k.key"
                    type="button"
                    role="radio"
                    :aria-checked="kind === k.key"
                    class="rounded-md px-2.5 py-1 text-[11px] font-semibold transition"
                    :class="
                        kind === k.key
                            ? k.key === 'simulacion'
                                ? 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300'
                                : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="kind = k.key"
                >
                    {{ k.label }}
                </button>
            </div>
            <FinInfoTip
                v-if="canUpload"
                :text="'Simulación: documento no vinculante del acreedor.\nDefinitivo: tabla confirmada.'"
                label="Simulación o definitivo"
            />
            <div class="ml-auto flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8"
                    @click="emit('template')"
                >
                    <Download class="size-3.5" /> Plantilla
                </Button>
                <Button
                    v-if="canUpload"
                    size="sm"
                    class="h-8 bg-emerald-700 text-white hover:bg-emerald-800"
                    :disabled="uploading"
                    @click="pick"
                >
                    <Loader2 v-if="uploading" class="size-3.5 animate-spin" />
                    <Upload v-else class="size-3.5" />
                    {{ up ? 'Sustituir' : 'Subir tabla' }}
                </Button>
            </div>
            <input
                ref="input"
                type="file"
                class="hidden"
                :accept="accept"
                :aria-label="`Tabla de amortización de ${credit.credit.name}`"
                @change="onFile"
            />
        </div>

        <Collapsible
            v-if="credit.history.length > 1"
            v-model:open="showHistory"
        >
            <CollapsibleTrigger
                class="flex items-center gap-1.5 text-[11px] font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
            >
                <History class="size-3.5" /> Bitácora ({{
                    credit.history.length
                }})
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ul class="mt-2 space-y-1 text-[11px]">
                    <li
                        v-for="h in credit.history"
                        :key="h.id"
                        class="flex items-center gap-2 rounded-lg bg-muted/40 px-2 py-1"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :class="
                                h.status === 'vigente'
                                    ? 'bg-emerald-500'
                                    : 'bg-slate-400'
                            "
                            :title="
                                h.status === 'vigente'
                                    ? 'Vigente'
                                    : `Sustituida ${h.superseded_at ?? ''}`
                            "
                        />
                        <span class="min-w-0 flex-1 truncate font-semibold">{{
                            h.name
                        }}</span>
                        <span class="shrink-0 text-muted-foreground"
                            >{{ h.rows }} pagos · {{ h.uploaded_at }}</span
                        >
                    </li>
                </ul>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>
