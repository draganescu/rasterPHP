SELECT name FROM menudata WHERE CAST(price AS INTEGER) BETWEEN :low AND :high AND (enabled IS NULL OR enabled != '0') ORDER BY name
