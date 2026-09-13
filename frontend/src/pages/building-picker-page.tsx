import { useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { buildingsOf, isSystemAdministrator } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { Panel } from '@/components/panel'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useFormatters } from '@/lib/format'

/**
 * Where a signed-in person lands.
 *
 * A staff member or resident is attached to buildings by their grants, so with
 * a single grant the screen is skipped entirely. The administrator holds the
 * role over the system and is attached to none — and this iteration of the
 * contract has no route that lists buildings — so the administrator opens a
 * building by its number. Inventing a client-side list of buildings here would
 * mean showing data the API never sent.
 */
export function BuildingPickerPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const navigate = useNavigate()
  const { session } = useSession()
  const [number, setNumber] = useState('1')

  if (session.status !== 'authenticated') {
    return null
  }

  const buildings = buildingsOf(session.user)

  if (buildings.length === 1) {
    return <Navigate to={`/buildings/${buildings[0]}`} replace />
  }

  function open(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const parsed = Number(number)
    if (Number.isInteger(parsed) && parsed > 0) {
      void navigate(`/buildings/${parsed}`)
    }
  }

  return (
    <div className="grid gap-6">
      <h1 className="text-2xl font-semibold text-ink">{t('picker.title')}</h1>

      {buildings.length > 1 ? (
        <Panel caption={t('picker.title')}>
          <p className="px-4 pt-4 text-steel">{t('picker.fromGrants')}</p>
          <ul className="px-4 py-4">
            {buildings.map((id) => (
              <li key={id} className="border-b border-rule/70 py-2 last:border-b-0">
                <Button asChild variant="link" className="px-0">
                  <Link to={`/buildings/${id}`}>
                    {t('roles.scopeBuilding', { id: formatters.count(id) })}
                  </Link>
                </Button>
              </li>
            ))}
          </ul>
        </Panel>
      ) : null}

      {buildings.length === 0 ? (
        <Panel caption={t('picker.title')}>
          <div className="px-4 py-5">
            <p className="text-steel">
              {isSystemAdministrator(session.user)
                ? t('picker.adminLead')
                : t('picker.noGrants')}
            </p>
            {isSystemAdministrator(session.user) ? (
              <form className="mt-4 flex flex-wrap items-end gap-3" onSubmit={open}>
                <div className="grid gap-1">
                  <Label htmlFor="building-number" className="label-caps">
                    {t('picker.numberLabel')}
                  </Label>
                  <Input
                    id="building-number"
                    type="number"
                    min={1}
                    inputMode="numeric"
                    className="w-32"
                    value={number}
                    onChange={(event) => setNumber(event.target.value)}
                  />
                </div>
                <Button type="submit">{t('common.open')}</Button>
              </form>
            ) : null}
          </div>
        </Panel>
      ) : null}
    </div>
  )
}
