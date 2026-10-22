declare module '*.ttf' {
  const asset: number
  export default asset
}

// NativeWind's entry stylesheet, imported once for its side effect in app/_layout.tsx.
declare module '*.css'

declare module '*.png' {
  const asset: number
  export default asset
}
