/**
 * The UI kit's parts, as plain React Native ones, for a screen test that is about logic and words rather than looks.
 * Kept out of the `jest.mock` factory because the NativeWind JSX transform rewrites element creation there.
 */

import { createElement, type ReactNode } from 'react'
import { Text as RNText } from 'react-native'

export const Text = ({ children }: { children?: ReactNode }) => createElement(RNText, null, children)
export const AppBar = ({ title }: { title: string }) => createElement(RNText, null, title)
export const Icon = () => null
// A pressable Text, not React Native's Pressable (gate:pressable): a press on the label reaches its onPress.
export const Pressable = ({ children, onPress, ...rest }: { children?: ReactNode; onPress?: () => void; 'aria-label'?: string }) =>
  createElement(RNText, { onPress, accessibilityLabel: rest['aria-label'] }, children)
export const a = () => 'transparent'
export const c = () => 'transparent'
export const useFidelityReady = () => undefined
export const isFidelity = false
