-- ============================================================================
-- MediCare Cloud — Migration 002 (Upgrade v2, 2026-11-06)
-- The front desk collects patient payments in the receptionist panel
-- (receptionist/billing.php), so the receptionist role also needs the
-- billing.manage permission (it already had billing.view + payments.view).
-- Idempotent: safe to run more than once. Folded into database.sql too.
-- ============================================================================
USE medicare_cloud;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.slug = 'billing.manage'
 WHERE r.slug = 'receptionist'
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );
