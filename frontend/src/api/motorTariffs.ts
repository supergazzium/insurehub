// Phase 9 — motor tariff admin CRUD.
import { api } from './client'

export interface MotorActTariff {
  id: string
  vehicleTypeCode: string
  labelTh: string
  labelEn: string | null
  premium: number
  active: boolean
}

export function fetchMotorTariffs() {
  return api.get<{ data: MotorActTariff[] }>('motor-act-tariffs')
}

export function createMotorTariff(payload: {
  vehicleTypeCode: string
  labelTh: string
  labelEn?: string
  premium: number
  active?: boolean
}) {
  return api.post<{ data: MotorActTariff }>('motor-act-tariffs', payload)
}

export function updateMotorTariff(id: string, payload: {
  labelTh?: string
  labelEn?: string | null
  premium?: number
  active?: boolean
}) {
  return api.patch<{ data: MotorActTariff }>(`motor-act-tariffs/${id}`, payload)
}

export function deleteMotorTariff(id: string) {
  return api.delete<{ message: string }>(`motor-act-tariffs/${id}`)
}
