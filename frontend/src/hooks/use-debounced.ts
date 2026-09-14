import { useEffect, useState } from 'react'

/**
 * The value a search box settles on. Every keystroke would otherwise be a
 * request; the list follows the typing by one pause instead.
 */
export function useDebounced<T>(value: T, delay = 300): T {
  const [settled, setSettled] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => {
      setSettled(value)
    }, delay)
    return () => {
      clearTimeout(timer)
    }
  }, [value, delay])

  return settled
}
