-- Optional one-off fix for the existing Adidas item described during testing.
-- Review the matching row before running in production.
UPDATE WardrobeItems
SET Brand = 'Adidas'
WHERE (Brand IS NULL OR Brand = '')
  AND Name LIKE 'Adidas %';
