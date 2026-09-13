import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListConsents,
  useListNotificationSettings,
  useUpdateNotificationSettings,
  type listConsentsResponse,
  type listNotificationSettingsResponse,
} from '@/api/generated/dormitory'
import {
  ConsentDocument,
  type NotificationCategory,
  type NotificationSetting,
} from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Skeleton } from '@/components/ui/skeleton'
import { useAccountRefresh } from '@/lib/account-cache'

/**
 * FR-34, second criterion: «the user can disable non-mandatory categories».
 *
 * **Why the mandatory ones carry no switch at all.** The obvious rendering is
 * one list of switches with three of them greyed out, and it is the wrong one.
 * A disabled control says «not now» — it invites the reader to look for the
 * condition that would enable it, and there is none: the server refuses the
 * change with 422 and the database would refuse it after that. What a person
 * actually needs to know is *why*, and the why is not a rule of this
 * application. A mandatory category carries what the accommodation contract or
 * the rules of internal order oblige the dormitory to tell them, so switching
 * it off would be a refusal of the service rather than a preference. The two
 * groups are therefore drawn as two different things: a list of choices, and a
 * list of obligations with the ground of each one named beside it.
 *
 * **Why `mandatory` is not a list kept here.** It arrives per category in the
 * answer, and the contract says why in as many words: which categories are
 * mandatory is a matter of the legal ground the message rests on, the server
 * decides it, and a client holding a copy of the decision would eventually draw
 * a switch the server refuses to move. So the grouping below is driven by the
 * flag and never by the category code. The legal ground *is* named per category
 * in the locale files, because a translated sentence cannot come from an API
 * that answers in one language; if a category ever arrives mandatory without a
 * ground written for it, the screen says that the server named no ground rather
 * than inventing one.
 *
 * **The switch is not the whole answer.** An optional category rests on the
 * consent of FR-35 and stops when that consent is withdrawn, whatever its
 * switch says — `User::receivesNotificationsOf()` asks both questions. A screen
 * showing «enabled» on a category that is in fact silenced would be a lie of
 * exactly the kind FR-34's criterion is meant to prevent, so the state of the
 * consent is read here too and said plainly above the group.
 */
export function NotificationSettingsPage() {
  const { t } = useTranslation()
  const refresh = useAccountRefresh()
  const [pending, setPending] = useState<NotificationCategory | null>(null)

  const settings = useListNotificationSettings<listNotificationSettingsResponse, ApiError>({
    query: { retry: false },
  })

  const consents = useListConsents<listConsentsResponse, ApiError>({
    query: { retry: false },
  })

  const update = useUpdateNotificationSettings<ApiError>()

  const rows = settings.data?.status === 200 ? settings.data.data.data : null
  const optional = rows?.filter((row) => !row.mandatory) ?? []
  const mandatory = rows?.filter((row) => row.mandatory) ?? []

  /*
   * Null while the history has not arrived: «we have not asked» is not «no
   * consent stands», and only the second one may be put on the screen.
   */
  const consentStands =
    consents.data?.status === 200
      ? consents.data.data.data.some(
          (record) =>
            record.document === ConsentDocument.resident_personal_data && record.in_force,
        )
      : null

  function toggle(row: NotificationSetting, next: boolean) {
    setPending(row.category)
    update.mutate(
      { data: { categories: { [row.category]: next } } },
      {
        onSettled: () => setPending(null),
        onSuccess: (response) => {
          if (response.status === 200) {
            refresh()
          }
        },
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('notificationSettings.heading')}</h1>
        <p className="mt-1 text-steel">{t('notificationSettings.lead')}</p>
      </div>

      <nav aria-label={t('notifications.filterLabel')}>
        <ul className="m-0 flex list-none flex-wrap gap-2 p-0">
          <li>
            <Link
              className="inline-block border border-rule bg-paper-raised px-3 py-2 font-medium text-prussian underline-offset-4 hover:underline"
              to="/notifications"
            >
              {t('notificationSettings.toList')}
            </Link>
          </li>
          <li>
            <Link
              className="inline-block border border-rule bg-paper-raised px-3 py-2 font-medium text-prussian underline-offset-4 hover:underline"
              to="/consents"
            >
              {t('notificationSettings.toConsents')}
            </Link>
          </li>
        </ul>
      </nav>

      {settings.isError ? <RequestRefusal error={settings.error} /> : null}
      {update.isError ? <RequestRefusal error={update.error} /> : null}

      {settings.isPending && !settings.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-32 w-full" />
          <Skeleton className="h-32 w-full" />
        </div>
      ) : null}

      {/*
        Said above the switches rather than beside one of them, because it is
        true of the whole group: without a consent in force the optional
        categories are silent whatever position their switch is in.
      */}
      {consentStands === false && optional.length > 0 ? (
        <section className="border-l-4 border-brass bg-brass-wash px-4 py-4" role="note">
          <h2 className="m-0 text-lg font-semibold text-ink">
            {t('notificationSettings.silencedTitle')}
          </h2>
          <p className="mt-2 mb-0 text-ink">{t('notificationSettings.silencedBody')}</p>
          <p className="mt-2 mb-0">
            <Link className="text-prussian underline" to="/consent">
              {t('notificationSettings.silencedWayOut')}
            </Link>
          </p>
        </section>
      ) : null}

      {optional.length > 0 ? (
        <Panel
          className="min-w-0"
          caption={t('notificationSettings.optionalCaption')}
          aside={t('notificationSettings.optionalCount', { count: optional.length })}
        >
          <p className="border-b border-rule/70 px-4 py-3 text-steel">
            {t('notificationSettings.optionalNote')}
          </p>
          <ul className="m-0 list-none p-0">
            {optional.map((row) => (
              <li key={row.category} className="border-b border-rule/70 last:border-b-0">
                <label className="flex min-w-0 cursor-pointer items-start gap-3 px-4 py-4">
                  <input
                    type="checkbox"
                    className="mt-1 size-6 shrink-0 accent-prussian"
                    checked={row.enabled}
                    disabled={pending !== null}
                    onChange={(event) => toggle(row, event.target.checked)}
                  />
                  <span className="grid min-w-0 gap-1">
                    <span className="font-medium text-ink">
                      {t(`notificationCategory.${row.category}.label`, {
                        defaultValue: row.label,
                      })}
                    </span>
                    <span className="text-steel">
                      {t(`notificationCategory.${row.category}.description`, {
                        defaultValue: row.description,
                      })}
                    </span>
                    <span className="text-steel">
                      {pending === row.category
                        ? `${t('common.saving')}…`
                        : row.enabled
                          ? t('notificationSettings.stateOn')
                          : t('notificationSettings.stateOff')}
                    </span>
                  </span>
                </label>
              </li>
            ))}
          </ul>
        </Panel>
      ) : null}

      {mandatory.length > 0 ? (
        <Panel
          className="min-w-0"
          caption={t('notificationSettings.mandatoryCaption')}
          aside={t('notificationSettings.mandatoryCount', { count: mandatory.length })}
        >
          <p className="border-b border-rule/70 px-4 py-3 text-ink">
            {t('notificationSettings.mandatoryNote')}
          </p>
          <ul className="m-0 list-none p-0">
            {mandatory.map((row) => (
              <li
                key={row.category}
                className="grid min-w-0 gap-2 border-b border-rule/70 px-4 py-4 last:border-b-0"
              >
                <div className="flex min-w-0 flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                  <span className="font-medium text-ink">
                    {t(`notificationCategory.${row.category}.label`, {
                      defaultValue: row.label,
                    })}
                  </span>
                  <span className="inline-block border border-prussian/40 bg-prussian-wash px-2 py-0.5 font-medium whitespace-nowrap text-prussian">
                    {t('notificationSettings.always')}
                  </span>
                </div>
                <p className="m-0 text-steel">
                  {t(`notificationCategory.${row.category}.description`, {
                    defaultValue: row.description,
                  })}
                </p>
                {/*
                  The ground, named. This is the sentence the whole screen is
                  built around: not «you may not switch this off» but «this
                  stands on the accommodation contract», which is a statement
                  the reader can check and, if they disagree, argue with.
                */}
                <p className="m-0 border-l-4 border-brass bg-brass-wash px-3 py-2 text-ink">
                  {t(`notificationCategory.${row.category}.ground`, {
                    defaultValue: t('notificationSettings.groundUnstated'),
                  })}
                </p>
              </li>
            ))}
          </ul>
        </Panel>
      ) : null}
    </div>
  )
}
