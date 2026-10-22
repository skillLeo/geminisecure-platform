/**
 * M24 §7 (D-223): the tab of a page somebody reaches without signing in names the company, never the product.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { COMPANY, tabTitle } from '../../resources/js/tabTitle.js'

test('a visitor who is not signed in reads the company name, with or without a page title', () => {
    assert.equal(tabTitle('Sign in', false, 'GeminiSecure'), 'Sign in · Gemini Security Limited')
    assert.equal(tabTitle('Not found', false, 'GeminiSecure'), 'Not found · Gemini Security Limited')
    assert.equal(tabTitle('', false, 'GeminiSecure'), COMPANY)
    assert.doesNotMatch(tabTitle('Forgot password', false, 'GeminiSecure'), /GeminiSecure/)
})

test('somebody signed in keeps the name of the product they work in', () => {
    assert.equal(tabTitle('Dashboard', true, 'GeminiSecure'), 'Dashboard · GeminiSecure')
    assert.equal(tabTitle(null, true, 'GeminiSecure'), 'GeminiSecure')
})
