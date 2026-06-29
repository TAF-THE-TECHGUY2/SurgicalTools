import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { Badge, StatusBadge } from '@/components/ui/Badge'

describe('Badge', () => {
  it('renders its children', () => {
    render(<Badge tone="green">Active</Badge>)
    expect(screen.getByText('Active')).toBeInTheDocument()
  })
})

describe('StatusBadge', () => {
  it('humanises the status label', () => {
    render(<StatusBadge status="pending_approval" />)
    expect(screen.getByText('Pending Approval')).toBeInTheDocument()
  })

  it('maps a completed status to the green tone', () => {
    render(<StatusBadge status="completed" />)
    const badge = screen.getByText('Completed')
    // emerald = the "green" tone in the design system
    expect(badge.className).toContain('emerald')
  })

  it('falls back to the gray tone for unknown statuses', () => {
    render(<StatusBadge status="something_new" />)
    const badge = screen.getByText('Something New')
    expect(badge.className).toContain('slate')
  })
})
