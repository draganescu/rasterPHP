SELECT COUNT(*) AS total FROM menudata WHERE category = ? AND (enabled IS NULL OR enabled != '0')
