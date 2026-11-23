// Declara los archivos .vue como módulos para que TypeScript permita importarlos.
declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent;
    export default component;
}
