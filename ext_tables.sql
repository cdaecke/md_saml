CREATE TABLE be_users
(
	md_saml_source tinyint(1) unsigned DEFAULT '0' NOT NULL,
);

CREATE TABLE fe_users
(
	md_saml_source tinyint(1) unsigned DEFAULT '0' NOT NULL,
);
