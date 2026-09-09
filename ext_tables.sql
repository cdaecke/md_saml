CREATE TABLE be_users
(
	md_saml_source tinyint(1) unsigned DEFAULT '0' NOT NULL,
	md_saml_nameid varchar(255) DEFAULT '' NOT NULL,
	md_saml_nameid_format varchar(255) DEFAULT '' NOT NULL,
	md_saml_session_index varchar(255) DEFAULT '' NOT NULL,
	md_saml_identity varchar(255) DEFAULT '' NOT NULL,

	KEY md_saml_identity (md_saml_identity)
);

CREATE TABLE fe_users
(
	md_saml_source tinyint(1) unsigned DEFAULT '0' NOT NULL,
	md_saml_nameid varchar(255) DEFAULT '' NOT NULL,
	md_saml_nameid_format varchar(255) DEFAULT '' NOT NULL,
	md_saml_session_index varchar(255) DEFAULT '' NOT NULL,
	md_saml_identity varchar(255) DEFAULT '' NOT NULL,

	KEY md_saml_identity (md_saml_identity)
);
