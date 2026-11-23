/** Ids compartidos entre la pestaña (role=tab) y su panel (role=tabpanel). */
/**
 * Id del botón de pestaña.
 *
 * @param prefix Prefijo común de la barra.
 * @param key Clave de la pestaña.
 */
export const tabDomId = (prefix: string, key: string) => `${prefix}-tab-${key}`;
/**
 * Id del panel de la pestaña.
 *
 * @param prefix Prefijo común de la barra.
 * @param key Clave de la pestaña.
 */
export const tabPanelDomId = (prefix: string, key: string) =>
    `${prefix}-panel-${key}`;
