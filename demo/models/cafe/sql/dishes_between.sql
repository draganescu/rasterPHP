SELECT name FROM menudata WHERE price >= :low AND price < :high + 1 AND (enabled IS NULL OR enabled != '0') ORDER BY name
