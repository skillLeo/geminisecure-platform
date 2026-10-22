/** @type {import('jest').Config} */
module.exports = {
  preset: 'jest-expo',
  rootDir: '.',
  testMatch: ['<rootDir>/src/**/__tests__/**/*.test.ts?(x)'],
  setupFiles: ['<rootDir>/../../packages/core/jest.setup.js'],
  moduleNameMapper: { '^@/(.*)$': '<rootDir>/src/$1' },
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|expo(nent)?|@expo(nent)?/.*|@expo-google-fonts/.*|react-navigation|@react-navigation/.*|@sentry/react-native|native-base|react-native-svg|@reduxjs/toolkit|react-redux|immer|redux|redux-thunk|reselect|nativewind|react-native-css-interop))',
  ],
}
