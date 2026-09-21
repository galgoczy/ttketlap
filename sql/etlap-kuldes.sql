-- Etlapkuldes: a beerkezett PDF-ekbol keszulo kikuldesek.
-- Futtasd le egyszer a Hostinger phpMyAdmin feluleten, a schema.sql utan.

CREATE TABLE IF NOT EXISTS etlap_kuldes (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- A beerkezett level azonositoja a postafiokban. Egyedi kulcs, mert ez
  -- akadalyozza meg, hogy ugyanabbol a levelbol ketszer induljon kuldes.
  uzenet_id         VARCHAR(255)  NOT NULL,

  felado            VARCHAR(190)  NOT NULL,
  targy             VARCHAR(255)  NOT NULL,
  bevezeto          TEXT          NOT NULL,

  pdf_nev           VARCHAR(255)  NOT NULL,
  pdf_meret         INT UNSIGNED  NOT NULL,
  -- A PDF-et az adatbazisban taroljuk, nem fajlkent: a tarhelyre az
  -- automata deploy dolgozik, es nem akarjuk, hogy egy kikuldes kozben
  -- futo deploy elvigye a csatolmanyt a lab alol.
  pdf_tartalom      MEDIUMBLOB    NOT NULL,

  -- A "Megsem" linkhez. Ugyanaz az elv, mint a leiratkozasnal.
  token             CHAR(64)      NOT NULL,

  status            ENUM('elonezet','kuldes','kesz','megszakitva','hiba')
                    NOT NULL DEFAULT 'elonezet',

  -- Eddig var a rendszer, mielott elindulna a kikuldes.
  kuldes_ideje      DATETIME      NOT NULL,

  -- Meddig jutottunk: az utolso cimzett azonositoja. A kuldes ez alapjan
  -- folytatodik a kovetkezo futaskor, igy egy megszakadt futas sem kuld
  -- senkinek ketszer.
  utolso_cimzett_id INT UNSIGNED  NOT NULL DEFAULT 0,
  kikuldve          INT UNSIGNED  NOT NULL DEFAULT 0,
  hibas             INT UNSIGNED  NOT NULL DEFAULT 0,
  hiba_uzenet       VARCHAR(500)  DEFAULT NULL,

  letrehozva        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  befejezve         DATETIME      DEFAULT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_uzenet (uzenet_id),
  UNIQUE KEY uniq_token (token),
  KEY idx_status (status, kuldes_ideje)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Apro kulcs-ertek tabla a rendszer allapotahoz (pl. mikor futott utoljara
-- a futar). A diagnosztika oldal ebbol tudja megmondani, el-e az automata.
CREATE TABLE IF NOT EXISTS rendszer_allapot (
  kulcs     VARCHAR(64) NOT NULL,
  ertek     VARCHAR(255) NOT NULL,
  frissitve DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (kulcs)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
