/*
 * THE BROWSER TAB'S TITLE (M24 §7, D-223). Somebody who is not signed in (the sign-in doors, a page that is not
 * found) reads the company's name, Gemini Security Limited, as on every public page; the product's name is for the
 * people inside it. Pure, so tests/js reads it without a browser.
 */
export const COMPANY = 'Gemini Security Limited'

export function tabTitle(title, signedIn, productName) {
    const name = signedIn ? productName : COMPANY

    return title ? `${title} · ${name}` : name
}
