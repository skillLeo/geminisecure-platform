// Used by Jest only — the apps bundle this package with their own Babel config.
module.exports = function (api) {
  api.cache(true)
  return { presets: ['babel-preset-expo'] }
}
