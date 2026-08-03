-- Sample BYD Cars Data
INSERT IGNORE INTO `cars` (`model_name`, `model_name_ar`, `model_code`, `year`, `category`, `price_from`) VALUES
('BYD Seal',       'بي واي دي سيل',      'BYD_SEAL_2024',   2024, 'sedan',     NULL),
('BYD Atto 3',     'بي واي دي آتو 3',    'BYD_ATTO3_2024',  2024, 'suv',       NULL),
('BYD Han',        'بي واي دي هان',       'BYD_HAN_2024',    2024, 'sedan',     NULL),
('BYD Tang',       'بي واي دي تانج',      'BYD_TANG_2024',   2024, 'suv',       NULL),
('BYD Dolphin',    'بي واي دي دولفين',    'BYD_DOLPHIN_2024',2024, 'hatchback', NULL);

-- Sample specs for BYD Seal
INSERT IGNORE INTO `specifications` (`car_id`, `spec_key`, `spec_value`, `spec_group`, `unit`, `display_order`) VALUES
(1, 'battery_capacity',   '82.56',  'battery',     'kWh',  1),
(1, 'range_wltp',         '570',    'battery',     'km',   2),
(1, 'charge_fast_dc',     '150',    'battery',     'kW',   3),
(1, 'power_rwd',          '230',    'performance', 'kW',   1),
(1, 'torque_rwd',         '360',    'performance', 'Nm',   2),
(1, 'acceleration_0_100', '5.9',    'performance', 'sec',  3),
(1, 'top_speed',          '180',    'performance', 'km/h', 4),
(1, 'length',             '4800',   'dimensions',  'mm',   1),
(1, 'width',              '1875',   'dimensions',  'mm',   2),
(1, 'height',             '1460',   'dimensions',  'mm',   3),
(1, 'wheelbase',          '2920',   'dimensions',  'mm',   4),
(1, 'weight_curb',        '1885',   'dimensions',  'kg',   5);
