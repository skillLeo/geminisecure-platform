/**
 * Which panic alert action is next, in the workflow's own order (Hassam, Batch 1, 12 Oct 2026) — `lib/alertSteps.js`.
 *
 * WRITTEN, NOT RUN. The project's JS test runner is banned on this machine (a memory fault that froze it three
 * times); this file parses (`node --check`) and its assertions compare plain strings, never a rendered element. Run
 * it once, alone, with the flag on the command, when JS tests are allowed again — the same hold account-page.test.mjs
 * is under.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { nextStep, stepClass } from '../../resources/js/lib/alertSteps.js'

const NONE = {}

test('a fresh alert asks for acknowledge first', () => {
    assert.equal(nextStep(NONE), 'acknowledge')
    assert.equal(stepClass(NONE, 'acknowledge'), 'primary')
    assert.equal(stepClass(NONE, 'resolve'), 'outline')
})

test('once acknowledged, dispatch is next — an officer taking it from their own handset counts the same as being sent', () => {
    assert.equal(nextStep({ acknowledged: true }), 'dispatch')
    assert.equal(nextStep({ acknowledged: true, dispatched: true }), 'en_route')
    assert.equal(nextStep({ acknowledged: true, responding: true }), 'en_route')
})

test('on the way, then on scene, each in its turn', () => {
    assert.equal(nextStep({ acknowledged: true, dispatched: true, en_route: true }), 'on_scene')
    assert.equal(nextStep({ acknowledged: true, dispatched: true, on_scene: true }), 'resolve')
})

test('resolve is the last step, and stays next once everything else is done', () => {
    const everything = { acknowledged: true, dispatched: true, en_route: true, on_scene: true }
    assert.equal(nextStep(everything), 'resolve')
    assert.equal(stepClass(everything, 'resolve'), 'primary')
    assert.equal(stepClass(everything, 'dispatch'), 'outline')
})

