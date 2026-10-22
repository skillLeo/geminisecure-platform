/**
 * WHAT A TABLE'S ADDRESS KEEPS WHEN THE SEARCH OR A FILTER CHANGES (M32 F5).
 *
 * Sorting never clears the search: the table's own links change one word of the address and keep the rest. And a new
 * search, or a new filter, keeps the sort, its way and the page size — and starts again from the first page, because
 * page 4 of one search is not page 4 of another.
 *
 * @param {object|null|undefined} table  `App\Support\TableQuery::meta()`
 * @returns {{ sort?: string, dir?: string, per?: number }}
 */
export const keptByTable = (table) => {
    const kept = {}
    // A page that carries more than one table names each one's words with its own prefix: `gate_sort`, `gate_per`.
    const word = (name) => `${table?.prefix ?? ''}${name}`

    if (table?.sort) {
        kept[word('sort')] = table.sort
        kept[word('dir')] = table.dir ?? 'asc'
    }

    // The table's own page size says nothing the bare address does not; any other is kept.
    if (table?.per && table.per !== (table.default?.per ?? table.sizes?.[0] ?? 25)) {
        kept[word('per')] = table.per
    }

    return kept
}
