import Constants, { ExecutionEnvironment } from 'expo-constants'
import { Platform } from 'react-native'

/**
 * True inside the Expo Go app. Native modules that are not part of Expo Go
 * (MMKV, jail-monkey, SSL pinning) do not exist there, and evaluating them
 * crashes at launch — so every one of them is `require`d lazily behind this.
 */
export const isExpoGo: boolean = Constants.executionEnvironment === ExecutionEnvironment.StoreClient

export const isWeb: boolean = Platform.OS === 'web'

/** The API root, e.g. http://192.168.18.119:8000/api/v1 — never localhost: on a phone that is the phone. */
export function apiUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL
  if (!url) throw new Error('EXPO_PUBLIC_API_URL is not set. Copy .env.example to .env.development.')
  return url.replace(/\/+$/, '')
}
