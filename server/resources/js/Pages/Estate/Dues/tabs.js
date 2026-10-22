/**
 * Board 5's four tabs, in the board's own order, for every screen that draws
 * them.
 *
 * ONE LIST, because four copies of it drifted once already: two screens went on
 * calling a tab "not built yet" after it was. All four tabs are built now (12 §2,
 * items 17 and 18, and the receipts ruling), so every one is a link except the
 * screen the reader is already on.
 *
 * @param {(suffix: string) => string} financePath  roots a path at this estate's finance module
 * @param {'arrears'|'schedule'|'plans'|'receipts'} active
 */
export const duesTabs = (financePath, active) => [
    // "Arrears", the word a treasurer says; "command centre" was the board's name for the screen.
    { key: 'arrears', label: 'Arrears', href: financePath('/arrears') },
    { key: 'schedule', label: 'Charge schedule', href: financePath('/charge-schedule') },
    { key: 'plans', label: 'Payment plans', href: financePath('/payment-plans') },
    { key: 'receipts', label: 'Receipts', href: financePath('/receipts') },
].map((tab) => ({ ...tab, active: tab.key === active }))
