-- Import once after the original wardrobe_schema.sql if WardrobeItems already exists.
ALTER TABLE WardrobeItems
  ADD COLUMN AIDescription TEXT DEFAULT NULL AFTER Notes,
  ADD COLUMN AnalysisConfidence JSON DEFAULT NULL AFTER MetadataSource,
  ADD COLUMN AnalysisWarnings JSON DEFAULT NULL AFTER AnalysisConfidence,
  ADD COLUMN AnalysisRawJSON JSON DEFAULT NULL AFTER AnalysisWarnings,
  ADD COLUMN AnalysisError VARCHAR(1000) DEFAULT NULL AFTER AnalysisRawJSON,
  ADD COLUMN AnalysedAt DATETIME DEFAULT NULL AFTER AnalysisError;
