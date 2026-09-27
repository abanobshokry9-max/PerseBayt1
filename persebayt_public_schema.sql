-- PerseBayt PUBLIC SAFE schema
-- Generated from the supplied production dump with all row data removed.
-- Contains schema only: no passwords, API keys, tokens, encrypted vault snapshots, contacts, messages, or production records.

-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- مضيف: 127.0.0.1:3306
-- وقت الجيل: 27 سبتمبر 2026 الساعة 18:16
-- إصدار الخادم: 11.8.9-MariaDB-log
-- نسخة PHP: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- قاعدة بيانات: `persebayt_public`
--

-- --------------------------------------------------------

--
-- بنية الجدول `agency_creators`
--

CREATE TABLE `agency_creators` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `creator_id` varchar(80) NOT NULL,
  `handle` varchar(190) DEFAULT NULL,
  `nickname` varchar(255) DEFAULT NULL,
  `group_name` varchar(190) DEFAULT NULL,
  `network_manager` varchar(190) DEFAULT NULL,
  `group_manager` varchar(190) DEFAULT NULL,
  `agency_account` varchar(190) DEFAULT NULL,
  `relationship_status` varchar(190) DEFAULT NULL,
  `subscription_status` varchar(190) DEFAULT NULL,
  `last_live_at` datetime DEFAULT NULL,
  `joined_at` datetime DEFAULT NULL,
  `followers` bigint(20) UNSIGNED DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `supervisor_contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agency_creator_monthly`
--

CREATE TABLE `agency_creator_monthly` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `creator_id` varchar(80) NOT NULL,
  `data_month` char(6) NOT NULL,
  `source_type` enum('creator_management','monthly_earnings') NOT NULL,
  `diamonds` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `valid_days` decimal(10,2) NOT NULL DEFAULT 0.00,
  `live_hours` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estimated_bonus` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `followers` bigint(20) UNSIGNED DEFAULT NULL,
  `videos` bigint(20) UNSIGNED DEFAULT NULL,
  `likes` bigint(20) UNSIGNED DEFAULT NULL,
  `fan_diamonds` bigint(20) UNSIGNED DEFAULT NULL,
  `active_fans` bigint(20) UNSIGNED DEFAULT NULL,
  `raw_json` longtext DEFAULT NULL,
  `import_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agency_creator_policies`
--

CREATE TABLE `agency_creator_policies` (
  `creator_id` varchar(80) NOT NULL,
  `no_official_matches` tinyint(1) NOT NULL DEFAULT 0,
  `no_tapping_campaigns` tinyint(1) NOT NULL DEFAULT 0,
  `preferred_followup_hours` int(10) UNSIGNED NOT NULL DEFAULT 6,
  `notes` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agency_events`
--

CREATE TABLE `agency_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `creator_id` varchar(100) DEFAULT NULL,
  `supervisor_contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `event_type` varchar(80) NOT NULL,
  `title` varchar(240) NOT NULL,
  `details_text` text DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `remind_at` datetime DEFAULT NULL,
  `next_action_at` datetime DEFAULT NULL,
  `state` enum('open','waiting_supervisor','waiting_creator','scheduled','completed','cancelled','failed') NOT NULL DEFAULT 'open',
  `resolution_code` varchar(80) DEFAULT NULL,
  `reply_confidence` tinyint(3) UNSIGNED DEFAULT NULL,
  `dedupe_key` char(64) DEFAULT NULL,
  `followup_id` bigint(20) UNSIGNED DEFAULT NULL,
  `thread_id` bigint(20) UNSIGNED DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agency_imports`
--

CREATE TABLE `agency_imports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `import_type` enum('creator_management','monthly_earnings','unknown') NOT NULL DEFAULT 'unknown',
  `source_filename` varchar(255) NOT NULL,
  `file_sha256` char(64) NOT NULL,
  `data_month` char(6) DEFAULT NULL,
  `rows_total` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rows_imported` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rows_skipped` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `state` enum('processing','completed','completed_warning','failed') NOT NULL DEFAULT 'processing',
  `summary_json` longtext DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agency_threads`
--

CREATE TABLE `agency_threads` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `creator_id` varchar(100) DEFAULT NULL,
  `contact_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `channel` varchar(40) NOT NULL DEFAULT 'whatsapp',
  `external_thread_ref` varchar(220) DEFAULT NULL,
  `state` enum('open','waiting_reply','closed') NOT NULL DEFAULT 'open',
  `last_message_at` datetime DEFAULT NULL,
  `last_inbound_id` varchar(220) DEFAULT NULL,
  `context_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agency_thread_messages`
--

CREATE TABLE `agency_thread_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `thread_id` bigint(20) UNSIGNED NOT NULL,
  `direction` enum('inbound','outbound') NOT NULL,
  `external_id` varchar(220) DEFAULT NULL,
  `body_text` longtext NOT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agents`
--

CREATE TABLE `agents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `slug` varchar(80) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `role_title` varchar(160) NOT NULL,
  `specialty` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `profile_contact_details` text DEFAULT NULL,
  `profile_work_details` text DEFAULT NULL,
  `profile_identity` varchar(190) DEFAULT NULL,
  `profile_age` smallint(5) UNSIGNED DEFAULT NULL,
  `manager_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('online','working','idle','disabled','error') NOT NULL DEFAULT 'idle',
  `provider_key` varchar(80) NOT NULL DEFAULT 'openai',
  `model` varchar(120) DEFAULT NULL,
  `system_prompt` longtext NOT NULL,
  `owner_communication` enum('disabled','through_ramy','dashboard_only','whatsapp','dashboard_whatsapp','emergency_only') NOT NULL DEFAULT 'through_ramy',
  `whatsapp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `current_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `last_error_code` varchar(160) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_autonomy`
--

CREATE TABLE `agent_autonomy` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `brain_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `initiative_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `cadence_minutes` int(10) UNSIGNED NOT NULL DEFAULT 120,
  `max_active_tasks` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `max_initiatives_per_day` int(10) UNSIGNED NOT NULL DEFAULT 6,
  `initiative_min_value` tinyint(3) UNSIGNED NOT NULL DEFAULT 60,
  `auto_execute_max_risk` varchar(20) NOT NULL DEFAULT 'low',
  `followup_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `learning_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `self_improvement_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `daily_ai_call_budget` int(10) UNSIGNED NOT NULL DEFAULT 60,
  `daily_external_action_budget` int(10) UNSIGNED NOT NULL DEFAULT 20,
  `daily_search_budget` int(10) UNSIGNED NOT NULL DEFAULT 40,
  `daily_media_budget` int(10) UNSIGNED NOT NULL DEFAULT 20,
  `daily_voice_budget` int(10) UNSIGNED NOT NULL DEFAULT 40,
  `daily_browser_budget` int(10) UNSIGNED NOT NULL DEFAULT 80,
  `mission_text` text DEFAULT NULL,
  `initiative_scope` enum('internal','owner_team','external_guarded') NOT NULL DEFAULT 'owner_team',
  `quiet_hours_json` longtext DEFAULT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `last_run_at` datetime DEFAULT NULL,
  `last_think_at` datetime DEFAULT NULL,
  `last_initiative_at` datetime DEFAULT NULL,
  `last_brain_state` varchar(80) DEFAULT NULL,
  `last_brain_note` varchar(600) DEFAULT NULL,
  `last_state` varchar(60) DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_budget_policies`
--

CREATE TABLE `agent_budget_policies` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `daily_ai_usd` decimal(12,4) DEFAULT NULL,
  `monthly_ai_usd` decimal(12,4) DEFAULT NULL,
  `daily_search_usd` decimal(12,4) DEFAULT NULL,
  `monthly_media_usd` decimal(12,4) DEFAULT NULL,
  `warn_percent` tinyint(3) UNSIGNED NOT NULL DEFAULT 80,
  `hard_stop` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_budget_reservations`
--

CREATE TABLE `agent_budget_reservations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `capability` varchar(60) NOT NULL,
  `estimated_cost_usd` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `state` enum('reserved','consumed','released','expired') NOT NULL DEFAULT 'reserved',
  `reference_key` varchar(190) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_builder_profiles`
--

CREATE TABLE `agent_builder_profiles` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `role_template` varchar(80) DEFAULT NULL,
  `memory_mode` varchar(40) NOT NULL DEFAULT 'layered',
  `semantic_memory_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_capability_assignments`
--

CREATE TABLE `agent_capability_assignments` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `capability_key` varchar(100) NOT NULL,
  `access_level` enum('read','use','manage','approve') NOT NULL DEFAULT 'use',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `requires_owner_approval` tinyint(1) DEFAULT NULL,
  `requires_backup` tinyint(1) DEFAULT NULL,
  `config_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_capability_catalog`
--

CREATE TABLE `agent_capability_catalog` (
  `capability_key` varchar(100) NOT NULL,
  `label_ar` varchar(220) NOT NULL,
  `description_text` text DEFAULT NULL,
  `tool_key` varchar(100) DEFAULT NULL,
  `permission_keys_json` longtext DEFAULT NULL,
  `default_config_json` longtext DEFAULT NULL,
  `default_access_level` enum('read','use','manage','approve') NOT NULL DEFAULT 'use',
  `risk_level` enum('none','low','medium','high','critical','destructive') NOT NULL DEFAULT 'low',
  `requires_owner_approval` tinyint(1) NOT NULL DEFAULT 0,
  `requires_backup` tinyint(1) NOT NULL DEFAULT 0,
  `external_action` tinyint(1) NOT NULL DEFAULT 0,
  `production_sensitive` tinyint(1) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `version_no` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_capability_route_preferences`
--

CREATE TABLE `agent_capability_route_preferences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `capability` varchar(60) NOT NULL,
  `provider_key` varchar(80) NOT NULL,
  `model_key` varchar(190) DEFAULT NULL,
  `priority_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_change_proposals`
--

CREATE TABLE `agent_change_proposals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `proposal_type` enum('memory','instruction','permission','tool','workflow','code_patch','schema_change','integration','test_case') NOT NULL,
  `title` varchar(220) NOT NULL,
  `summary` text NOT NULL,
  `reason_text` text DEFAULT NULL,
  `risk_level` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `payload_json` longtext DEFAULT NULL,
  `before_snapshot_json` longtext DEFAULT NULL,
  `after_snapshot_json` longtext DEFAULT NULL,
  `state` enum('pending','approved','queued','implemented','rejected','failed','cancelled') NOT NULL DEFAULT 'pending',
  `rollback_state` enum('not_available','available','rolled_back','rollback_failed') NOT NULL DEFAULT 'not_available',
  `implementation_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `owner_note` varchar(1000) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `implemented_at` datetime DEFAULT NULL,
  `rolled_back_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_change_rollbacks`
--

CREATE TABLE `agent_change_rollbacks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `proposal_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `change_type` varchar(60) NOT NULL,
  `before_json` longtext DEFAULT NULL,
  `after_json` longtext DEFAULT NULL,
  `state` enum('available','rolled_back','failed') NOT NULL DEFAULT 'available',
  `rolled_back_by` varchar(100) DEFAULT NULL,
  `rolled_back_at` datetime DEFAULT NULL,
  `error_text` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_channel_permissions`
--

CREATE TABLE `agent_channel_permissions` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `channel_key` varchar(80) NOT NULL,
  `can_send_owner` tinyint(1) NOT NULL DEFAULT 0,
  `can_receive_owner` tinyint(1) NOT NULL DEFAULT 0,
  `can_start` tinyint(1) NOT NULL DEFAULT 0,
  `requires_ramy_approval` tinyint(1) NOT NULL DEFAULT 1,
  `emergency_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `allowed_hours_json` longtext DEFAULT NULL,
  `rate_limit_per_hour` int(10) UNSIGNED NOT NULL DEFAULT 10,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_data_fields`
--

CREATE TABLE `agent_data_fields` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `schema_id` bigint(20) UNSIGNED NOT NULL,
  `field_key` varchar(80) NOT NULL,
  `label` varchar(160) NOT NULL,
  `field_type` enum('text','long_text','number','decimal','date','datetime','boolean','json','url','email','phone','status') NOT NULL DEFAULT 'text',
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `default_value` longtext DEFAULT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 10,
  `config_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_data_rows`
--

CREATE TABLE `agent_data_rows` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `schema_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `row_label` varchar(190) DEFAULT NULL,
  `data_json` longtext NOT NULL,
  `created_by_type` enum('owner','agent','system') NOT NULL DEFAULT 'owner',
  `created_by_id` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_data_schemas`
--

CREATE TABLE `agent_data_schemas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `schema_key` varchar(80) NOT NULL,
  `label` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `scope` enum('agent','project') NOT NULL DEFAULT 'agent',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_external_actions`
--

CREATE TABLE `agent_external_actions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `initiative_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `channel_key` varchar(60) NOT NULL,
  `action_key` varchar(100) NOT NULL,
  `state` enum('proposed','authorized','queued','executed','blocked','failed','cancelled') NOT NULL DEFAULT 'proposed',
  `reason_text` text DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `requires_owner_approval` tinyint(1) NOT NULL DEFAULT 0,
  `approved_at` datetime DEFAULT NULL,
  `executed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_followups`
--

CREATE TABLE `agent_followups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `channel` varchar(40) NOT NULL DEFAULT 'dashboard',
  `purpose` varchar(220) NOT NULL,
  `message_text` text DEFAULT NULL,
  `due_at` datetime NOT NULL,
  `state` enum('scheduled','queued','completed','cancelled','failed') NOT NULL DEFAULT 'scheduled',
  `created_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `resolution_state` varchar(60) DEFAULT NULL,
  `resolution_note` varchar(1000) DEFAULT NULL,
  `resolved_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_executed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_goal_contributions`
--

CREATE TABLE `agent_goal_contributions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `goal_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `initiative_id` bigint(20) UNSIGNED DEFAULT NULL,
  `expected_delta` decimal(20,6) DEFAULT NULL,
  `actual_delta` decimal(20,6) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_initiatives`
--

CREATE TABLE `agent_initiatives` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` varchar(40) DEFAULT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `goal_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `initiative_type` varchar(80) NOT NULL DEFAULT 'proactive_work',
  `proposed_action` varchar(120) DEFAULT NULL,
  `value_score` tinyint(3) UNSIGNED NOT NULL DEFAULT 50,
  `risk_level` varchar(30) NOT NULL DEFAULT 'low',
  `requires_owner_approval` tinyint(1) NOT NULL DEFAULT 0,
  `dedupe_key` char(64) DEFAULT NULL,
  `reasoning_summary` text DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `executed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `expected_delta` decimal(20,6) DEFAULT NULL,
  `actual_delta` decimal(20,6) DEFAULT NULL,
  `title` varchar(220) NOT NULL,
  `summary` text DEFAULT NULL,
  `rationale` text DEFAULT NULL,
  `state` varchar(40) NOT NULL DEFAULT 'proposed',
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_kpi_daily`
--

CREATE TABLE `agent_kpi_daily` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `kpi_date` date NOT NULL,
  `kpi_key` varchar(120) NOT NULL,
  `value_number` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `source_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_kpi_definitions`
--

CREATE TABLE `agent_kpi_definitions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `kpi_key` varchar(120) NOT NULL,
  `label_ar` varchar(220) NOT NULL,
  `unit_label` varchar(60) DEFAULT NULL,
  `direction_key` enum('maximize','minimize','informational') NOT NULL DEFAULT 'informational',
  `weight` decimal(6,3) NOT NULL DEFAULT 1.000,
  `target_value` decimal(18,4) DEFAULT NULL,
  `baseline_value` decimal(18,4) DEFAULT NULL,
  `min_value` decimal(18,4) DEFAULT NULL,
  `max_value` decimal(18,4) DEFAULT NULL,
  `period_days` smallint(5) UNSIGNED NOT NULL DEFAULT 30,
  `normalization_key` enum('target_ratio','inverse_target','range','percentage','informational') NOT NULL DEFAULT 'target_ratio',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_learning`
--

CREATE TABLE `agent_learning` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `learning_type` enum('experience','procedure','owner_preference','quality_rule','search_preference','technical_pattern') NOT NULL DEFAULT 'experience',
  `learning_text` text NOT NULL,
  `status` enum('pending','approved','disabled') NOT NULL DEFAULT 'pending',
  `promoted_to_memory_id` bigint(20) UNSIGNED DEFAULT NULL,
  `confidence` tinyint(3) UNSIGNED NOT NULL DEFAULT 60,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `promoted_to_instruction_at` datetime DEFAULT NULL,
  `owner_note` varchar(500) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_memory`
--

CREATE TABLE `agent_memory` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `memory_type` enum('core','experience','project','owner_preference','relationship','procedure') NOT NULL,
  `body_text` text NOT NULL,
  `source_type` varchar(60) DEFAULT NULL,
  `source_id` varchar(100) DEFAULT NULL,
  `importance` tinyint(3) UNSIGNED NOT NULL DEFAULT 50,
  `content_hash` char(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_memory_bank`
--

CREATE TABLE `agent_memory_bank` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `memory_kind` enum('episodic','semantic','procedural','entity','goal','feedback') NOT NULL DEFAULT 'episodic',
  `title` varchar(240) NOT NULL,
  `body_text` longtext NOT NULL,
  `entity_key` varchar(240) DEFAULT NULL,
  `salience` tinyint(3) UNSIGNED NOT NULL DEFAULT 50,
  `confidence` tinyint(3) UNSIGNED NOT NULL DEFAULT 70,
  `source_type` varchar(60) DEFAULT NULL,
  `source_id` varchar(190) DEFAULT NULL,
  `occurred_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `consolidated_into_id` bigint(20) UNSIGNED DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `embedding_json` longtext DEFAULT NULL,
  `access_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_memory_links`
--

CREATE TABLE `agent_memory_links` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `from_memory_id` bigint(20) UNSIGNED NOT NULL,
  `to_memory_id` bigint(20) UNSIGNED NOT NULL,
  `relation_key` varchar(80) NOT NULL DEFAULT 'related',
  `strength` decimal(5,4) NOT NULL DEFAULT 0.5000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_performance_snapshots`
--

CREATE TABLE `agent_performance_snapshots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `score` decimal(7,3) DEFAULT NULL,
  `metrics_json` longtext DEFAULT NULL,
  `lessons_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_permissions`
--

CREATE TABLE `agent_permissions` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_permission_sources`
--

CREATE TABLE `agent_permission_sources` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `source_type` enum('capability','role_template','owner_manual','system','legacy_manual') NOT NULL,
  `source_key` varchar(160) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_policy_decisions`
--

CREATE TABLE `agent_policy_decisions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `capability_key` varchar(140) DEFAULT NULL,
  `permission_key` varchar(140) DEFAULT NULL,
  `action_key` varchar(180) DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `decision` varchar(20) NOT NULL DEFAULT 'deny',
  `decision_state` varchar(40) DEFAULT NULL,
  `risk_level` varchar(30) NOT NULL DEFAULT 'low',
  `reason_code` varchar(190) DEFAULT NULL,
  `owner_approval_required` tinyint(1) NOT NULL DEFAULT 0,
  `owner_approved` tinyint(1) NOT NULL DEFAULT 0,
  `reason_json` longtext DEFAULT NULL,
  `context_json` longtext DEFAULT NULL,
  `rule_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_policy_rules`
--

CREATE TABLE `agent_policy_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `action_pattern` varchar(180) NOT NULL,
  `required_capability` varchar(140) DEFAULT NULL,
  `required_permission` varchar(140) DEFAULT NULL,
  `required_access` varchar(20) NOT NULL DEFAULT '',
  `risk_level` varchar(30) NOT NULL DEFAULT 'low',
  `is_external` tinyint(1) NOT NULL DEFAULT 0,
  `touches_production` tinyint(1) NOT NULL DEFAULT 0,
  `backup_required` tinyint(1) NOT NULL DEFAULT 0,
  `owner_approval_required` tinyint(1) NOT NULL DEFAULT 0,
  `external_requires_approval` tinyint(1) NOT NULL DEFAULT 1,
  `budget_capability` varchar(60) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_project_access`
--

CREATE TABLE `agent_project_access` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `access_scope` enum('read','work','manage') NOT NULL DEFAULT 'read'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_prompt_versions`
--

CREATE TABLE `agent_prompt_versions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `proposal_id` bigint(20) UNSIGNED DEFAULT NULL,
  `version_no` int(10) UNSIGNED NOT NULL,
  `prompt_text` longtext NOT NULL,
  `change_summary` varchar(1000) DEFAULT NULL,
  `created_by_type` varchar(40) NOT NULL DEFAULT 'owner',
  `created_by_id` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_provider_routes`
--

CREATE TABLE `agent_provider_routes` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `route_order` tinyint(3) UNSIGNED NOT NULL,
  `provider_key` varchar(80) NOT NULL,
  `model` varchar(160) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `last_state` enum('untested','ok','failed') NOT NULL DEFAULT 'untested',
  `last_error` varchar(300) DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_relationships`
--

CREATE TABLE `agent_relationships` (
  `from_agent_id` bigint(20) UNSIGNED NOT NULL,
  `to_agent_id` bigint(20) UNSIGNED NOT NULL,
  `can_message` tinyint(1) NOT NULL DEFAULT 0,
  `can_start` tinyint(1) NOT NULL DEFAULT 0,
  `requires_manager_approval` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_role_templates`
--

CREATE TABLE `agent_role_templates` (
  `template_key` varchar(80) NOT NULL,
  `label_ar` varchar(190) NOT NULL,
  `description_text` text DEFAULT NULL,
  `default_capabilities_json` longtext DEFAULT NULL,
  `default_permissions_json` longtext DEFAULT NULL,
  `default_tools_json` longtext DEFAULT NULL,
  `relationship_policy` enum('executive','team','restricted','direct_owner') NOT NULL DEFAULT 'team',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_runs`
--

CREATE TABLE `agent_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` enum('started','completed','failed','blocked') NOT NULL DEFAULT 'started',
  `provider_key` varchar(80) DEFAULT NULL,
  `model` varchar(120) DEFAULT NULL,
  `input_tokens` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `output_tokens` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `summary` varchar(600) DEFAULT NULL,
  `error_code` varchar(120) DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_schema_requests`
--

CREATE TABLE `agent_schema_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `label` varchar(220) NOT NULL,
  `fields_json` longtext NOT NULL,
  `reason_text` text DEFAULT NULL,
  `state` varchar(40) NOT NULL DEFAULT 'proposed',
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `error_text` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_tools`
--

CREATE TABLE `agent_tools` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `tool_key` varchar(100) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_tool_sources`
--

CREATE TABLE `agent_tool_sources` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `tool_key` varchar(100) NOT NULL,
  `source_type` enum('capability','role_template','owner_manual','system','legacy_manual') NOT NULL,
  `source_key` varchar(160) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_usage_daily`
--

CREATE TABLE `agent_usage_daily` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `usage_date` date NOT NULL,
  `ai_calls` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `external_actions` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `search_requests` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `media_requests` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `voice_actions` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `browser_actions` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `estimated_cost` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_usage_events`
--

CREATE TABLE `agent_usage_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `usage_type` varchar(60) NOT NULL,
  `usage_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `estimated_cost` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_usage_ledger`
--

CREATE TABLE `agent_usage_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `provider_key` varchar(80) NOT NULL,
  `model_key` varchar(190) DEFAULT NULL,
  `capability` varchar(60) NOT NULL DEFAULT 'text',
  `input_tokens` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `output_tokens` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `estimated_cost_usd` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_workflow_assignments`
--

CREATE TABLE `agent_workflow_assignments` (
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `workflow_id` bigint(20) UNSIGNED NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_workflow_runs`
--

CREATE TABLE `agent_workflow_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workflow_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` varchar(40) NOT NULL DEFAULT 'running',
  `current_step` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `waiting_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `context_json` longtext DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `policy_decision_id` bigint(20) UNSIGNED DEFAULT NULL,
  `error_text` varchar(1000) DEFAULT NULL,
  `error_code` varchar(190) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_workflow_run_steps`
--

CREATE TABLE `agent_workflow_run_steps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `step_no` int(10) UNSIGNED NOT NULL,
  `action_key` varchar(100) NOT NULL,
  `state` varchar(40) NOT NULL DEFAULT 'pending',
  `result_json` longtext DEFAULT NULL,
  `error_text` varchar(1000) DEFAULT NULL,
  `error_code` varchar(190) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_workflow_steps`
--

CREATE TABLE `agent_workflow_steps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workflow_id` bigint(20) UNSIGNED NOT NULL,
  `step_no` int(10) UNSIGNED NOT NULL,
  `label_ar` varchar(220) NOT NULL,
  `action_key` varchar(120) NOT NULL,
  `config_json` longtext DEFAULT NULL,
  `blocking` tinyint(1) NOT NULL DEFAULT 0,
  `on_failure` varchar(20) NOT NULL DEFAULT 'stop',
  `risk_level` varchar(30) NOT NULL DEFAULT 'low',
  `requires_owner_approval` tinyint(1) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `agent_workflow_templates`
--

CREATE TABLE `agent_workflow_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workflow_key` varchar(100) NOT NULL,
  `name` varchar(220) NOT NULL,
  `description` text DEFAULT NULL,
  `description_text` text DEFAULT NULL,
  `steps_json` longtext NOT NULL,
  `risk_level` varchar(30) NOT NULL DEFAULT 'low',
  `requires_owner_approval` tinyint(1) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `version_no` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `state` varchar(40) NOT NULL DEFAULT 'active',
  `created_by_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `config_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `actor_type` enum('owner','agent','customer','system','anonymous') NOT NULL,
  `actor_id` varchar(100) DEFAULT NULL,
  `action` varchar(140) NOT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` varchar(100) DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `result` enum('attempted','executed','verified','failed','blocked') NOT NULL DEFAULT 'executed',
  `ip_address` varchar(45) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `backups`
--

CREATE TABLE `backups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `provider` varchar(80) NOT NULL,
  `kind` enum('files','database','full','restore_point') NOT NULL,
  `location_ref` varchar(500) DEFAULT NULL,
  `checksum` varchar(128) DEFAULT NULL,
  `status` enum('requested','created','verified','failed','restored') NOT NULL DEFAULT 'requested',
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `calls`
--

CREATE TABLE `calls` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `channel_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `direction` enum('inbound','outbound') NOT NULL,
  `from_ref` varchar(120) DEFAULT NULL,
  `to_ref` varchar(120) DEFAULT NULL,
  `purpose` varchar(600) DEFAULT NULL,
  `provider_call_id` varchar(190) DEFAULT NULL,
  `state` enum('requested','queued','ringing','connected','completed','failed','requires_review','unsupported') NOT NULL DEFAULT 'requested',
  `transcript` longtext DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `raw_json` longtext DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `change_requests`
--

CREATE TABLE `change_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `request_text` longtext NOT NULL,
  `size` enum('small','large') NOT NULL DEFAULT 'small',
  `status` enum('new','owner_review','approved','working','completed','rejected') NOT NULL DEFAULT 'new',
  `owner_decision_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `cloud_objects`
--

CREATE TABLE `cloud_objects` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `provider_key` varchar(80) NOT NULL,
  `object_key` varchar(1000) NOT NULL,
  `local_path_hash` char(64) DEFAULT NULL,
  `size_bytes` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `checksum` char(64) DEFAULT NULL,
  `state` enum('queued','uploaded','verified','failed','deleted') NOT NULL DEFAULT 'queued',
  `remote_ref` varchar(1200) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `communication_channels`
--

CREATE TABLE `communication_channels` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel_key` varchar(80) NOT NULL,
  `label` varchar(160) NOT NULL,
  `channel_type` enum('dashboard','whatsapp','sms','voice','generic') NOT NULL,
  `provider` varchar(80) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `send_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `receive_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `owner_only` tinyint(1) NOT NULL DEFAULT 0,
  `config_json` longtext DEFAULT NULL,
  `last_test_state` enum('untested','verified','failed') NOT NULL DEFAULT 'untested',
  `last_test_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `communication_events`
--

CREATE TABLE `communication_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel_id` bigint(20) UNSIGNED DEFAULT NULL,
  `message_id` bigint(20) UNSIGNED DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `state` varchar(60) DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `company_goals`
--

CREATE TABLE `company_goals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(240) NOT NULL,
  `description_text` text DEFAULT NULL,
  `metric_key` varchar(120) DEFAULT NULL,
  `target_value` decimal(18,4) DEFAULT NULL,
  `current_value` decimal(18,4) DEFAULT NULL,
  `progress_percent` decimal(7,3) DEFAULT NULL,
  `forecast_value` decimal(18,4) DEFAULT NULL,
  `health_state` enum('unknown','on_track','at_risk','behind','achieved') NOT NULL DEFAULT 'unknown',
  `last_metric_sync_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `unit_label` varchar(60) DEFAULT NULL,
  `start_at` datetime DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `priority` enum('low','normal','high','critical') NOT NULL DEFAULT 'normal',
  `state` enum('draft','active','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
  `owner_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `company_goal_agents`
--

CREATE TABLE `company_goal_agents` (
  `goal_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `responsibility` varchar(500) DEFAULT NULL,
  `weight` decimal(6,3) NOT NULL DEFAULT 1.000,
  `state` enum('active','paused','completed') NOT NULL DEFAULT 'active',
  `last_progress_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `company_goal_events`
--

CREATE TABLE `company_goal_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `goal_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `initiative_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `progress_delta` decimal(18,4) DEFAULT NULL,
  `note_text` text DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `company_memory`
--

CREATE TABLE `company_memory` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `category` varchar(80) NOT NULL,
  `body_text` text NOT NULL,
  `source` varchar(80) DEFAULT NULL,
  `content_hash` char(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `connection_tests`
--

CREATE TABLE `connection_tests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `provider_key` varchar(80) NOT NULL,
  `state` enum('verified','failed') NOT NULL,
  `result_code` varchar(160) DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `contact_communication_policies`
--

CREATE TABLE `contact_communication_policies` (
  `contact_id` bigint(20) UNSIGNED NOT NULL,
  `timezone` varchar(80) NOT NULL DEFAULT 'Africa/Cairo',
  `quiet_start` time DEFAULT '23:00:00',
  `quiet_end` time DEFAULT '09:00:00',
  `weekend_days_json` varchar(80) DEFAULT '[5]',
  `max_messages_per_day` tinyint(3) UNSIGNED NOT NULL DEFAULT 4,
  `min_gap_minutes` int(10) UNSIGNED NOT NULL DEFAULT 120,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `contact_directory`
--

CREATE TABLE `contact_directory` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `display_name` varchar(190) NOT NULL,
  `role_title` varchar(190) DEFAULT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `whatsapp_phone` varchar(80) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `telegram_ref` varchar(190) DEFAULT NULL,
  `instagram_ref` varchar(190) DEFAULT NULL,
  `tiktok_ref` varchar(190) DEFAULT NULL,
  `group_key` varchar(120) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `metadata_json` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `conversations`
--

CREATE TABLE `conversations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `channel_key` varchar(80) NOT NULL,
  `provider` varchar(80) NOT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `external_thread_id` varchar(190) DEFAULT NULL,
  `status` enum('open','closed','blocked') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `conversation_sessions`
--

CREATE TABLE `conversation_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `subject_type` enum('owner','customer','agent_pair') NOT NULL,
  `subject_key` varchar(190) NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `identity_announced` tinyint(1) NOT NULL DEFAULT 0,
  `active_project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `active_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `context_summary` longtext DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_activity_at` datetime NOT NULL DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `creator_intelligence_snapshots`
--

CREATE TABLE `creator_intelligence_snapshots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `creator_id` varchar(80) NOT NULL,
  `data_month` char(6) NOT NULL,
  `diamonds` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `previous_diamonds` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `mom_percent` decimal(12,4) DEFAULT NULL,
  `rolling_3m_avg` decimal(20,4) DEFAULT NULL,
  `baseline_diamonds` decimal(20,4) DEFAULT NULL,
  `gap_from_baseline` decimal(20,4) DEFAULT NULL,
  `trend_direction` varchar(20) NOT NULL DEFAULT 'new',
  `attention_score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `days_risk` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `hours_risk` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `target_gap` decimal(20,4) DEFAULT NULL,
  `projection_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `customers`
--

CREATE TABLE `customers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `display_name` varchar(190) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `external_ref` varchar(190) DEFAULT NULL,
  `primary_channel` varchar(40) DEFAULT NULL,
  `status` enum('lead','negotiating','active','completed','blocked') NOT NULL DEFAULT 'lead',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `customer_memory`
--

CREATE TABLE `customer_memory` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `memory_type` varchar(80) NOT NULL DEFAULT 'requirement',
  `body_text` text NOT NULL,
  `importance` tinyint(3) UNSIGNED NOT NULL DEFAULT 60,
  `content_hash` char(64) NOT NULL,
  `source_conversation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `customer_projects`
--

CREATE TABLE `customer_projects` (
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `relationship` enum('owner','contact','billing') NOT NULL DEFAULT 'owner',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `data_retention_policies`
--

CREATE TABLE `data_retention_policies` (
  `data_key` varchar(100) NOT NULL,
  `retention_days` int(10) UNSIGNED NOT NULL,
  `archive_before_delete` tinyint(1) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(1000) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `data_source_registry`
--

CREATE TABLE `data_source_registry` (
  `source_key` varchar(120) NOT NULL,
  `concept_key` varchar(120) NOT NULL,
  `status` enum('canonical','legacy_read_only','migrated','archive') NOT NULL,
  `replacement_source_key` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `deployments`
--

CREATE TABLE `deployments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` enum('attempted','executed','verified','failed','rolled_back') NOT NULL DEFAULT 'attempted',
  `version_ref` varchar(190) DEFAULT NULL,
  `before_hash` varchar(128) DEFAULT NULL,
  `after_hash` varchar(128) DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `free_model_catalog`
--

CREATE TABLE `free_model_catalog` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `provider_key` varchar(80) NOT NULL,
  `model_key` varchar(190) NOT NULL,
  `label` varchar(190) DEFAULT NULL,
  `free_kind` varchar(80) NOT NULL DEFAULT 'unknown',
  `source_url` varchar(1200) DEFAULT NULL,
  `api_base_url` varchar(500) DEFAULT NULL,
  `context_length` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `score` tinyint(3) UNSIGNED NOT NULL DEFAULT 50,
  `health_state` enum('untested','available','working','quota_exhausted','failed','disabled') NOT NULL DEFAULT 'untested',
  `last_error` varchar(500) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `discovered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_tested_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `free_model_scout_runs`
--

CREATE TABLE `free_model_scout_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `trigger_key` varchar(40) NOT NULL DEFAULT 'scheduler',
  `state` enum('running','completed','partial','failed') NOT NULL DEFAULT 'running',
  `summary_json` longtext DEFAULT NULL,
  `error_text` longtext DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `free_provider_candidates`
--

CREATE TABLE `free_provider_candidates` (
  `provider_key` varchar(80) NOT NULL,
  `label` varchar(160) NOT NULL,
  `driver` varchar(80) NOT NULL DEFAULT 'unknown',
  `base_url` varchar(500) DEFAULT NULL,
  `signup_url` varchar(800) DEFAULT NULL,
  `docs_url` varchar(800) DEFAULT NULL,
  `source_url` varchar(1200) DEFAULT NULL,
  `free_kind` varchar(80) NOT NULL DEFAULT 'unknown',
  `state` enum('new','human_required','configured','working','failed','disabled') NOT NULL DEFAULT 'new',
  `auto_route` tinyint(1) NOT NULL DEFAULT 0,
  `priority_order` smallint(5) UNSIGNED NOT NULL DEFAULT 100,
  `last_note` varchar(500) DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_checked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `goal_metric_definitions`
--

CREATE TABLE `goal_metric_definitions` (
  `metric_key` varchar(120) NOT NULL,
  `label_ar` varchar(220) NOT NULL,
  `calculation_key` varchar(120) NOT NULL,
  `unit_label` varchar(60) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `inbound_message_claims`
--

CREATE TABLE `inbound_message_claims` (
  `provider` varchar(80) NOT NULL,
  `external_id` varchar(255) NOT NULL,
  `state` enum('processing','processed','failed') NOT NULL DEFAULT 'processing',
  `message_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `kind` varchar(100) NOT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` enum('queued','running','done','failed','waiting','cancelled') NOT NULL DEFAULT 'queued',
  `priority` tinyint(3) UNSIGNED NOT NULL DEFAULT 50,
  `payload_json` longtext DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
  `available_at` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_at` datetime DEFAULT NULL,
  `lease_token` char(32) DEFAULT NULL,
  `lease_expires_at` datetime DEFAULT NULL,
  `next_retry_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` bigint(20) UNSIGNED DEFAULT NULL,
  `error_code` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `lab_environments`
--

CREATE TABLE `lab_environments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `root_domain` varchar(255) NOT NULL DEFAULT 'nourmakkah.com',
  `subdomain` varchar(190) NOT NULL,
  `full_domain` varchar(255) NOT NULL,
  `directory_path` varchar(500) NOT NULL,
  `database_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` enum('requested','preparing','ready','ready_with_warnings','ready_without_staging_db','file_sync_failed','needs_runtime_config_or_dns','syncing','testing','blocked','failed','archived') NOT NULL DEFAULT 'requested',
  `source_version_hash` varchar(128) DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `last_health_at` datetime DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `media_requests`
--

CREATE TABLE `media_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `requested_by_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `media_type` enum('image','video','ui_asset','audio') NOT NULL,
  `label` varchar(220) NOT NULL,
  `prompt_text` longtext NOT NULL,
  `provider` varchar(80) DEFAULT NULL,
  `provider_job_id` varchar(220) DEFAULT NULL,
  `idempotency_key` char(64) DEFAULT NULL,
  `next_poll_at` datetime DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `provider_state` varchar(120) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `state` enum('requested','submitted','processing','generating','completed','failed','cancelled') NOT NULL DEFAULT 'requested',
  `result_asset_id` bigint(20) UNSIGNED DEFAULT NULL,
  `error_code` varchar(160) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `messages`
--

CREATE TABLE `messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `channel_key` varchar(80) NOT NULL,
  `provider` varchar(80) NOT NULL,
  `direction` enum('inbound','outbound') NOT NULL,
  `sender_type` enum('owner','agent','customer','system') NOT NULL,
  `sender_ref` varchar(120) DEFAULT NULL,
  `receiver_type` enum('owner','agent','customer','system') NOT NULL,
  `receiver_ref` varchar(120) DEFAULT NULL,
  `message_type` enum('text','audio','image','document','interactive','call_event') NOT NULL DEFAULT 'text',
  `body_text` longtext DEFAULT NULL,
  `external_id` varchar(190) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'received',
  `raw_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `migration_preflight_runs`
--

CREATE TABLE `migration_preflight_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `target_version` varchar(40) NOT NULL,
  `state` enum('passed','failed') NOT NULL,
  `checks_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `severity` enum('info','success','warning','critical') NOT NULL DEFAULT 'info',
  `category` varchar(80) NOT NULL,
  `title` varchar(220) NOT NULL,
  `body_text` text DEFAULT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` varchar(100) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `opportunities`
--

CREATE TABLE `opportunities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(190) NOT NULL DEFAULT 'web',
  `source_item_id` varchar(190) DEFAULT NULL,
  `source_url` varchar(1200) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `discovered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `title` varchar(300) NOT NULL,
  `title_ar` varchar(300) DEFAULT NULL,
  `client_name` varchar(190) DEFAULT NULL,
  `client_contact` varchar(500) DEFAULT NULL,
  `contact_methods_json` longtext DEFAULT NULL,
  `contactability_notes` text DEFAULT NULL,
  `client_type` varchar(80) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  `language` varchar(20) DEFAULT NULL,
  `market_scope` enum('egypt','arab','international','unknown') NOT NULL DEFAULT 'unknown',
  `budget_min` decimal(14,2) DEFAULT NULL,
  `budget_max` decimal(14,2) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'UNK',
  `advertised_budget_text` varchar(500) DEFAULT NULL,
  `estimated_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cost_estimate_source` varchar(60) DEFAULT NULL,
  `cost_confidence` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `cost_learning_samples` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `suggested_offer` decimal(14,2) DEFAULT NULL,
  `suggested_deposit` decimal(14,2) DEFAULT NULL,
  `projected_profit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `estimated_days` smallint(5) UNSIGNED DEFAULT NULL,
  `score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `score_version` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `source_quality` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `intent_confidence` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `buyer_intent_evidence_json` longtext DEFAULT NULL,
  `fit_status` enum('qualified','review','rejected') NOT NULL DEFAULT 'review',
  `difficulty` enum('easy','medium','hard','unknown') NOT NULL DEFAULT 'unknown',
  `login_requirement` enum('public','login_required','api_required','unknown') NOT NULL DEFAULT 'unknown',
  `missing_fields_json` longtext DEFAULT NULL,
  `score_breakdown_json` longtext DEFAULT NULL,
  `selection_reason` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `last_verified_at` datetime DEFAULT NULL,
  `contact_verified_at` datetime DEFAULT NULL,
  `shortlist_rank` smallint(5) UNSIGNED DEFAULT NULL,
  `shortlisted_at` datetime DEFAULT NULL,
  `risk_level` varchar(80) DEFAULT NULL,
  `category` varchar(160) DEFAULT NULL,
  `opportunity_type` varchar(120) DEFAULT NULL,
  `tags_json` longtext DEFAULT NULL,
  `requirements_text` longtext DEFAULT NULL,
  `details_ar` longtext DEFAULT NULL,
  `summary_ar` text DEFAULT NULL,
  `raw_details` longtext DEFAULT NULL,
  `fingerprint` char(64) DEFAULT NULL,
  `discovered_by_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quote_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('new','needs_review','approved','rejected','contacted','negotiating','won','lost') NOT NULL DEFAULT 'new',
  `owner_decided_at` datetime DEFAULT NULL,
  `auto_rejected_at` datetime DEFAULT NULL,
  `ramy_contacted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `opportunity_cost_observations`
--

CREATE TABLE `opportunity_cost_observations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `opportunity_id` bigint(20) UNSIGNED NOT NULL,
  `category_key` varchar(160) DEFAULT NULL,
  `type_key` varchar(160) DEFAULT NULL,
  `difficulty` varchar(30) DEFAULT 'unknown',
  `currency` char(3) NOT NULL DEFAULT 'UNK',
  `estimated_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `owner_cost` decimal(14,2) DEFAULT NULL,
  `actual_cost` decimal(14,2) DEFAULT NULL,
  `source_kind` varchar(60) DEFAULT 'ai',
  `confidence` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `sample_count` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `opportunity_feedback`
--

CREATE TABLE `opportunity_feedback` (
  `opportunity_id` bigint(20) UNSIGNED NOT NULL,
  `owner_decision` enum('unknown','approved','rejected') NOT NULL DEFAULT 'unknown',
  `final_status` enum('unknown','contacted','negotiating','won','lost') NOT NULL DEFAULT 'unknown',
  `actual_revenue` decimal(14,2) DEFAULT NULL,
  `actual_cost` decimal(14,2) DEFAULT NULL,
  `actual_profit` decimal(14,2) DEFAULT NULL,
  `feedback_weight` decimal(7,3) NOT NULL DEFAULT 1.000,
  `learned_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `opportunity_fingerprints`
--

CREATE TABLE `opportunity_fingerprints` (
  `fingerprint` char(64) NOT NULL,
  `opportunity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `decision` enum('active','rejected','duplicate','won','lost') NOT NULL DEFAULT 'active',
  `title_normalized` varchar(300) DEFAULT NULL,
  `client_normalized` varchar(190) DEFAULT NULL,
  `domain_normalized` varchar(190) DEFAULT NULL,
  `source_normalized` varchar(120) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `opportunity_raw_items`
--

CREATE TABLE `opportunity_raw_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `source_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_name` varchar(160) DEFAULT NULL,
  `source_item_id` varchar(190) DEFAULT NULL,
  `source_url` varchar(1200) DEFAULT NULL,
  `title` varchar(300) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `raw_text` longtext DEFAULT NULL,
  `raw_hash` char(64) NOT NULL,
  `processing_state` enum('new','processed','duplicate','rejected','failed') NOT NULL DEFAULT 'new',
  `opportunity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `error_code` varchar(200) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `opportunity_search_runs`
--

CREATE TABLE `opportunity_search_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` enum('queued','running','completed','failed','partial') NOT NULL DEFAULT 'queued',
  `date_from` datetime DEFAULT NULL,
  `date_to` datetime DEFAULT NULL,
  `requested_limit` int(10) UNSIGNED NOT NULL DEFAULT 30,
  `queries_json` longtext DEFAULT NULL,
  `sources_json` longtext DEFAULT NULL,
  `raw_found` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `qualified_found` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `review_found` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rejected_found` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `duplicates_found` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `warning_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `summary_json` longtext DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `opportunity_sources`
--

CREATE TABLE `opportunity_sources` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(160) NOT NULL,
  `source_key` varchar(120) NOT NULL,
  `source_type` enum('web','rss','json','search_api','manual') NOT NULL DEFAULT 'web',
  `base_url` varchar(1200) DEFAULT NULL,
  `search_query_template` varchar(1200) DEFAULT NULL,
  `auth_requirement` enum('public','login_required','api_required') NOT NULL DEFAULT 'public',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `priority` tinyint(3) UNSIGNED NOT NULL DEFAULT 50,
  `config_json` longtext DEFAULT NULL,
  `last_run_at` datetime DEFAULT NULL,
  `last_state` enum('untested','ok','warning','failed') NOT NULL DEFAULT 'untested',
  `last_error` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `owner_channel_routes`
--

CREATE TABLE `owner_channel_routes` (
  `channel_key` varchar(80) NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `state` enum('active','closed') NOT NULL DEFAULT 'active',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `owner_decision_requests`
--

CREATE TABLE `owner_decision_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `dedupe_key` char(64) NOT NULL,
  `topic` varchar(160) NOT NULL,
  `summary_text` text NOT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` enum('pending','resolved','cancelled') NOT NULL DEFAULT 'pending',
  `whatsapp_state` enum('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  `whatsapp_error` varchar(300) DEFAULT NULL,
  `whatsapp_sent_at` datetime DEFAULT NULL,
  `repeat_count` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `payments`
--

CREATE TABLE `payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quote_id` bigint(20) UNSIGNED DEFAULT NULL,
  `payment_kind` enum('deposit','preview','final','other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'EGP',
  `method` varchar(120) DEFAULT NULL,
  `reference_text` varchar(190) DEFAULT NULL,
  `status` enum('pending','confirmed','failed','refunded') NOT NULL DEFAULT 'pending',
  `confirmed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `label` varchar(120) NOT NULL,
  `holder_name` varchar(190) DEFAULT NULL,
  `account_reference` varchar(190) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `show_on_quotes` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `pending_actions`
--

CREATE TABLE `pending_actions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `action_type` varchar(100) NOT NULL,
  `plan_json` longtext NOT NULL,
  `risk` enum('normal','destructive') NOT NULL DEFAULT 'normal',
  `state` enum('pending','executed','cancelled','expired','failed') NOT NULL DEFAULT 'pending',
  `requires_confirmation` tinyint(1) NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `executed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `permissions`
--

CREATE TABLE `permissions` (
  `permission_key` varchar(100) NOT NULL,
  `category` varchar(80) NOT NULL,
  `label` varchar(180) NOT NULL,
  `dangerous` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `physical_schema_requests`
--

CREATE TABLE `physical_schema_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `label` varchar(190) NOT NULL,
  `reason_text` text DEFAULT NULL,
  `fields_json` longtext NOT NULL,
  `before_snapshot_json` longtext DEFAULT NULL,
  `state` enum('pending','approved','applied','failed','rejected') NOT NULL DEFAULT 'pending',
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `resource_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `applied_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `projects`
--

CREATE TABLE `projects` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` char(16) NOT NULL,
  `opportunity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(190) NOT NULL,
  `primary_domain` varchar(190) DEFAULT NULL,
  `document_root` varchar(500) DEFAULT NULL,
  `hosting_provider` varchar(80) NOT NULL DEFAULT 'hostinger',
  `hosting_account` varchar(120) DEFAULT NULL,
  `provider_website_id` varchar(190) DEFAULT NULL,
  `technology` varchar(190) DEFAULT NULL,
  `status` enum('active','maintenance','broken','development','archived','unknown') NOT NULL DEFAULT 'unknown',
  `workflow_stage` varchar(40) NOT NULL DEFAULT 'discovered',
  `hosting_presence` enum('present','unavailable','removed','unknown') NOT NULL DEFAULT 'unknown',
  `last_seen_hosting_at` datetime DEFAULT NULL,
  `unavailable_since` datetime DEFAULT NULL,
  `current_brief_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source` enum('hosting_scan','client_project','manual','system') NOT NULL DEFAULT 'manual',
  `is_system_project` tinyint(1) NOT NULL DEFAULT 0,
  `last_scan_at` datetime DEFAULT NULL,
  `last_deployment_at` datetime DEFAULT NULL,
  `last_backup_at` datetime DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `project_assets`
--

CREATE TABLE `project_assets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `asset_type` enum('image','video','ui_asset','file','audio') NOT NULL DEFAULT 'file',
  `label` varchar(220) NOT NULL,
  `source` enum('uploaded','generated','external','code') NOT NULL DEFAULT 'external',
  `path_ref` varchar(1000) DEFAULT NULL,
  `url_ref` varchar(1200) DEFAULT NULL,
  `status` enum('active','missing','archived') NOT NULL DEFAULT 'active',
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `project_briefs`
--

CREATE TABLE `project_briefs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `version_no` int(10) UNSIGNED NOT NULL,
  `title` varchar(220) NOT NULL DEFAULT 'PROJECT MASTER BRIEF',
  `summary_text` longtext NOT NULL,
  `brief_json` longtext DEFAULT NULL,
  `source_snapshot_json` longtext DEFAULT NULL,
  `created_by_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by_owner` tinyint(1) NOT NULL DEFAULT 0,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `project_chat_messages`
--

CREATE TABLE `project_chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `sender_type` enum('owner','agent','system') NOT NULL,
  `sender_ref` varchar(120) DEFAULT NULL,
  `message_kind` enum('message','master_brief','client_update','handoff','qa','decision','system') NOT NULL DEFAULT 'message',
  `body_text` longtext NOT NULL,
  `source_type` varchar(80) DEFAULT NULL,
  `source_id` varchar(120) DEFAULT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `project_cost_entries`
--

CREATE TABLE `project_cost_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cost_kind` enum('ai','api','hosting','domain','theme_plugin','assets','contractor','developer','other') NOT NULL DEFAULT 'other',
  `amount` decimal(14,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'EGP',
  `note` text DEFAULT NULL,
  `source` varchar(80) NOT NULL DEFAULT 'manual',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('active','void') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `project_databases`
--

CREATE TABLE `project_databases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `environment` enum('production','staging') NOT NULL DEFAULT 'production',
  `source_database_id` bigint(20) UNSIGNED DEFAULT NULL,
  `db_name` varchar(190) NOT NULL,
  `db_user` varchar(190) DEFAULT NULL,
  `db_host` varchar(190) DEFAULT 'localhost',
  `db_port` int(10) UNSIGNED NOT NULL DEFAULT 3306,
  `provider_ref` varchar(190) DEFAULT NULL,
  `status` enum('active','missing','unknown','removed') NOT NULL DEFAULT 'unknown',
  `connection_state` enum('unconfigured','verified','failed') NOT NULL DEFAULT 'unconfigured',
  `last_connection_test_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `project_domains`
--

CREATE TABLE `project_domains` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `domain_name` varchar(190) NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `provider_id` varchar(190) DEFAULT NULL,
  `status` enum('active','available','missing','removed') NOT NULL DEFAULT 'active',
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `project_files`
--

CREATE TABLE `project_files` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `path` varchar(700) NOT NULL,
  `item_type` enum('file','directory','symlink','unknown') NOT NULL DEFAULT 'unknown',
  `extension` varchar(30) DEFAULT NULL,
  `size_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `is_important` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','missing') NOT NULL DEFAULT 'active',
  `last_scan_id` bigint(20) UNSIGNED DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `project_issues`
--

CREATE TABLE `project_issues` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `severity` enum('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `title` varchar(220) NOT NULL,
  `page_label` varchar(220) DEFAULT NULL,
  `url` varchar(1200) DEFAULT NULL,
  `file_path` varchar(1200) DEFAULT NULL,
  `description` longtext NOT NULL,
  `expected_text` longtext DEFAULT NULL,
  `actual_text` longtext DEFAULT NULL,
  `repro_steps` longtext DEFAULT NULL,
  `blocking_delivery` tinyint(1) NOT NULL DEFAULT 0,
  `fix_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `retest_review_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cycle_no` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `device` varchar(120) DEFAULT NULL,
  `browser` varchar(120) DEFAULT NULL,
  `status` enum('open','working','resolved','ignored') NOT NULL DEFAULT 'open',
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `project_memory`
--

CREATE TABLE `project_memory` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `category` varchar(80) NOT NULL,
  `body_text` text NOT NULL,
  `source_type` varchar(60) DEFAULT NULL,
  `source_id` varchar(100) DEFAULT NULL,
  `content_hash` char(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `project_scans`
--

CREATE TABLE `project_scans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` enum('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
  `websites_found` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `projects_updated` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `domains_updated` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `databases_found` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `summary_json` longtext DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `project_stage_events`
--

CREATE TABLE `project_stage_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `from_stage` varchar(40) DEFAULT NULL,
  `to_stage` varchar(40) NOT NULL,
  `actor_type` enum('owner','agent','customer','system') NOT NULL DEFAULT 'system',
  `actor_id` varchar(100) DEFAULT NULL,
  `reason` varchar(800) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `prospecting_runs`
--

CREATE TABLE `prospecting_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `market_scope` varchar(80) DEFAULT NULL,
  `query_text` text DEFAULT NULL,
  `state` enum('queued','running','completed','partial','failed') NOT NULL DEFAULT 'queued',
  `raw_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `qualified_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `error_code` varchar(220) DEFAULT NULL,
  `diagnostics_json` longtext DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `prospect_contact_evidence`
--

CREATE TABLE `prospect_contact_evidence` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lead_id` bigint(20) UNSIGNED NOT NULL,
  `contact_type` enum('email','phone','whatsapp','facebook','instagram','telegram','linkedin','x','tiktok','other') NOT NULL,
  `contact_value` varchar(1200) NOT NULL,
  `source_url` varchar(1200) DEFAULT NULL,
  `source_kind` varchar(80) DEFAULT NULL,
  `confidence` tinyint(3) UNSIGNED NOT NULL DEFAULT 60,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `prospect_leads`
--

CREATE TABLE `prospect_leads` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `discovered_by_agent_id` bigint(20) UNSIGNED NOT NULL,
  `company_name` varchar(300) NOT NULL,
  `country` varchar(120) DEFAULT NULL,
  `industry` varchar(160) DEFAULT NULL,
  `source_url` varchar(1200) NOT NULL,
  `social_url` varchar(1200) DEFAULT NULL,
  `website_url` varchar(1200) DEFAULT NULL,
  `website_state` enum('none','broken','weak','present','unknown') NOT NULL DEFAULT 'unknown',
  `contact_methods_json` longtext DEFAULT NULL,
  `opportunity_reason` text DEFAULT NULL,
  `recommended_service` varchar(220) DEFAULT NULL,
  `score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `status` enum('new','qualified','contact_ready','rejected','contacted','converted') NOT NULL DEFAULT 'new',
  `opportunity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `fingerprint` char(64) NOT NULL,
  `audit_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `prospect_prototypes`
--

CREATE TABLE `prospect_prototypes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lead_id` bigint(20) UNSIGNED NOT NULL,
  `opportunity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ayman_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `emad_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ramy_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `staging_url` varchar(1200) DEFAULT NULL,
  `state` enum('discovered','qualified','prototype_requested','prototype_building','prototype_qa','prototype_ready','ramy_outreach','contacted','converted','rejected','blocked','failed') NOT NULL DEFAULT 'discovered',
  `brief_json` longtext DEFAULT NULL,
  `qa_json` longtext DEFAULT NULL,
  `outreach_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `prospect_website_audits`
--

CREATE TABLE `prospect_website_audits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lead_id` bigint(20) UNSIGNED NOT NULL,
  `website_url` varchar(1200) DEFAULT NULL,
  `http_status` int(10) UNSIGNED DEFAULT NULL,
  `https_ok` tinyint(1) DEFAULT NULL,
  `mobile_ok` tinyint(1) DEFAULT NULL,
  `cta_ok` tinyint(1) DEFAULT NULL,
  `contact_form_ok` tinyint(1) DEFAULT NULL,
  `rtl_arabic_ok` tinyint(1) DEFAULT NULL,
  `broken_links_count` int(10) UNSIGNED DEFAULT NULL,
  `seo_basics_score` tinyint(3) UNSIGNED DEFAULT NULL,
  `performance_hint_ms` int(10) UNSIGNED DEFAULT NULL,
  `overall_score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `classification` enum('none','broken','weak','present','unknown') NOT NULL DEFAULT 'unknown',
  `findings_json` longtext DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `providers`
--

CREATE TABLE `providers` (
  `provider_key` varchar(80) NOT NULL,
  `kind` enum('ai','hosting','messaging','voice','browser','media','social','storage','generic') NOT NULL,
  `label` varchar(160) NOT NULL,
  `driver` varchar(80) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL,
  `status` enum('untested','verified','failed','disabled') NOT NULL DEFAULT 'untested',
  `last_error` varchar(190) DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `provider_capability_routes`
--

CREATE TABLE `provider_capability_routes` (
  `capability` varchar(60) NOT NULL,
  `route_order` tinyint(3) UNSIGNED NOT NULL,
  `provider_key` varchar(80) NOT NULL,
  `model_key` varchar(190) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `health_state` enum('untested','working','degraded','failed','disabled') NOT NULL DEFAULT 'untested',
  `last_latency_ms` int(10) UNSIGNED DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `config_json` longtext DEFAULT NULL,
  `estimated_cost_usd` decimal(14,6) DEFAULT NULL,
  `cost_unit` varchar(40) NOT NULL DEFAULT 'request',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `provider_pricing`
--

CREATE TABLE `provider_pricing` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `provider_key` varchar(80) NOT NULL,
  `model_pattern` varchar(190) NOT NULL DEFAULT '*',
  `capability` varchar(60) NOT NULL DEFAULT 'text',
  `input_per_million_usd` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `output_per_million_usd` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `request_usd` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `source_note` varchar(500) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `quotes`
--

CREATE TABLE `quotes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(220) NOT NULL,
  `scope_text` longtext NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'EGP',
  `deposit_percent` decimal(5,2) NOT NULL DEFAULT 40.00,
  `owner_exception_required` tinyint(1) NOT NULL DEFAULT 0,
  `negotiation_notes` text DEFAULT NULL,
  `status` enum('draft','owner_review','sent','accepted','rejected','expired') NOT NULL DEFAULT 'draft',
  `owner_approved` tinyint(1) NOT NULL DEFAULT 0,
  `customer_accepted_at` datetime DEFAULT NULL,
  `project_started_at` datetime DEFAULT NULL,
  `delivery_ready_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `ramy_repair_runs`
--

CREATE TABLE `ramy_repair_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scope_key` varchar(40) NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `session_id` bigint(20) UNSIGNED DEFAULT NULL,
  `request_text` longtext NOT NULL,
  `state` varchar(30) NOT NULL DEFAULT 'running',
  `diagnosis_json` longtext DEFAULT NULL,
  `changes_json` longtext DEFAULT NULL,
  `summary_text` longtext DEFAULT NULL,
  `error_text` longtext DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `release_history`
--

CREATE TABLE `release_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `version` varchar(120) NOT NULL,
  `schema_version` varchar(40) NOT NULL,
  `build_date` date NOT NULL,
  `source_checksum` char(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `release_setting_snapshots`
--

CREATE TABLE `release_setting_snapshots` (
  `release_key` varchar(80) NOT NULL,
  `setting_key` varchar(190) NOT NULL,
  `value_text` longtext DEFAULT NULL,
  `captured_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `resource_registry`
--

CREATE TABLE `resource_registry` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resource_uid` varchar(40) DEFAULT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `resource_type` varchar(80) NOT NULL,
  `resource_key` varchar(190) NOT NULL,
  `display_name` varchar(220) DEFAULT NULL,
  `label` varchar(220) NOT NULL,
  `owner_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `external_ref` varchar(500) DEFAULT NULL,
  `secret_ref` varchar(220) DEFAULT NULL,
  `config_json` longtext DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `state` varchar(50) NOT NULL DEFAULT 'active',
  `provider_key` varchar(100) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `policy_decision_id` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `resource_registry_events`
--

CREATE TABLE `resource_registry_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resource_id` bigint(20) UNSIGNED NOT NULL,
  `event_key` varchar(120) NOT NULL,
  `result_state` varchar(40) NOT NULL DEFAULT 'executed',
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `resource_requests`
--

CREATE TABLE `resource_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `resource_type` varchar(80) NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `payload_json` longtext NOT NULL,
  `risk_level` varchar(30) NOT NULL DEFAULT 'low',
  `requires_owner_approval` tinyint(1) NOT NULL DEFAULT 0,
  `state` enum('pending_approval','approved','executing','completed','failed','cancelled') NOT NULL DEFAULT 'pending_approval',
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `resource_id` bigint(20) UNSIGNED DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `reviews`
--

CREATE TABLE `reviews` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewer_agent_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('approved','approved_warning','rejected','needs_fixes','blocked') NOT NULL,
  `summary` longtext NOT NULL,
  `tests_passed` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `tests_failed` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `warnings` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `recommendation` text DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `review_tests`
--

CREATE TABLE `review_tests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `review_id` bigint(20) UNSIGNED NOT NULL,
  `test_type` varchar(80) NOT NULL,
  `test_name` varchar(220) NOT NULL,
  `expected` text DEFAULT NULL,
  `actual` text DEFAULT NULL,
  `status` enum('passed','failed','warning','skipped') NOT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `runtime_validation_runs`
--

CREATE TABLE `runtime_validation_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scenario_key` varchar(100) NOT NULL,
  `label_ar` varchar(220) NOT NULL,
  `mode_key` enum('probe','integration','e2e','synthetic_e2e') NOT NULL DEFAULT 'probe',
  `state` enum('queued','running','passed','partial','failed','blocked') NOT NULL DEFAULT 'queued',
  `requested_by` varchar(100) DEFAULT NULL,
  `summary_json` longtext DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `runtime_validation_scenarios`
--

CREATE TABLE `runtime_validation_scenarios` (
  `scenario_key` varchar(100) NOT NULL,
  `label_ar` varchar(220) NOT NULL,
  `mode_key` enum('probe','integration','e2e','synthetic_e2e') NOT NULL DEFAULT 'probe',
  `schedule_key` enum('manual','daily','weekly','after_deploy','after_config_change') NOT NULL DEFAULT 'manual',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL,
  `last_run_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `runtime_validation_steps`
--

CREATE TABLE `runtime_validation_steps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `step_key` varchar(100) NOT NULL,
  `label_ar` varchar(220) NOT NULL,
  `state` enum('passed','failed','blocked','skipped') NOT NULL,
  `details_text` text DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `schema_migrations`
--

CREATE TABLE `schema_migrations` (
  `migration_id` varchar(120) NOT NULL,
  `version` varchar(40) NOT NULL,
  `checksum_sha256` char(64) DEFAULT NULL,
  `state` enum('applied','verified','failed') NOT NULL DEFAULT 'applied',
  `details_json` longtext DEFAULT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  `verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `schema_migration_steps`
--

CREATE TABLE `schema_migration_steps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `migration_version` varchar(40) NOT NULL,
  `step_id` varchar(120) NOT NULL,
  `step_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `checksum_sha256` char(64) NOT NULL,
  `state` enum('pending','running','verified','failed') NOT NULL DEFAULT 'pending',
  `statement_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `last_error` varchar(700) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `schema_table_catalog`
--

CREATE TABLE `schema_table_catalog` (
  `table_name` varchar(190) NOT NULL,
  `state` varchar(40) NOT NULL,
  `canonical_table` varchar(190) DEFAULT NULL,
  `domain_key` varchar(120) DEFAULT NULL,
  `notes` varchar(1200) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `secure_config_snapshots`
--

CREATE TABLE `secure_config_snapshots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `label` varchar(160) NOT NULL,
  `envelope_b64` longtext NOT NULL,
  `envelope_sha256` char(64) NOT NULL,
  `config_sha256` char(64) NOT NULL,
  `source_ref` varchar(255) DEFAULT NULL,
  `state` varchar(40) NOT NULL DEFAULT 'verified',
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `secure_vault_snapshots`
--

CREATE TABLE `secure_vault_snapshots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `label` varchar(160) NOT NULL,
  `envelope_b64` longtext NOT NULL,
  `envelope_sha256` char(64) NOT NULL,
  `source_ref` varchar(255) DEFAULT NULL,
  `state` varchar(40) NOT NULL DEFAULT 'verified',
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `security_authorizations`
--

CREATE TABLE `security_authorizations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `target_id` bigint(20) UNSIGNED NOT NULL,
  `authorized_by_type` varchar(40) NOT NULL DEFAULT 'owner',
  `authorized_by_id` varchar(100) DEFAULT NULL,
  `scope_text` text NOT NULL,
  `allow_authenticated_tests` tinyint(1) NOT NULL DEFAULT 1,
  `allow_intrusive_tests` tinyint(1) NOT NULL DEFAULT 0,
  `allow_production_changes` tinyint(1) NOT NULL DEFAULT 0,
  `allow_data_proof` tinyint(1) NOT NULL DEFAULT 1,
  `valid_from` datetime NOT NULL DEFAULT current_timestamp(),
  `valid_until` datetime DEFAULT NULL,
  `state` enum('active','expired','revoked') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `security_findings`
--

CREATE TABLE `security_findings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `target_id` bigint(20) UNSIGNED NOT NULL,
  `test_event_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `finding_key` varchar(190) NOT NULL,
  `title_ar` varchar(255) NOT NULL,
  `technical_name` varchar(220) DEFAULT NULL,
  `severity` enum('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `difficulty` enum('very_easy','easy','moderate','hard','very_hard','unknown') NOT NULL DEFAULT 'unknown',
  `status` enum('open','assigned','fixing','ready_retest','retesting','fixed','accepted_risk','false_positive','reopened') NOT NULL DEFAULT 'open',
  `description_text` text NOT NULL,
  `impact_text` text DEFAULT NULL,
  `proof_text` text DEFAULT NULL,
  `remediation_text` text DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `assigned_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `fix_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_verified_at` datetime DEFAULT NULL,
  `fixed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `security_retests`
--

CREATE TABLE `security_retests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `finding_id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `state` enum('queued','running','passed','failed','blocked') NOT NULL DEFAULT 'queued',
  `result_text` text DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `security_targets`
--

CREATE TABLE `security_targets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `label` varchar(220) NOT NULL,
  `base_url` varchar(1200) NOT NULL,
  `environment` enum('testing','staging','production','external_authorized') NOT NULL DEFAULT 'testing',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `scope_json` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_type` varchar(40) NOT NULL DEFAULT 'owner',
  `created_by_id` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `security_test_cases`
--

CREATE TABLE `security_test_cases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `test_key` varchar(120) NOT NULL,
  `label_ar` varchar(220) NOT NULL,
  `category` varchar(100) NOT NULL,
  `test_mode` enum('automatic_safe','browser_safe','manual','intrusive_guarded') NOT NULL DEFAULT 'automatic_safe',
  `severity_if_failed` enum('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `description_text` text DEFAULT NULL,
  `remediation_text` text DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `version_no` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `security_test_events`
--

CREATE TABLE `security_test_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `test_case_id` bigint(20) UNSIGNED DEFAULT NULL,
  `test_key` varchar(120) NOT NULL,
  `state` enum('started','passed','finding','blocked','manual_required','error','skipped') NOT NULL,
  `expected_text` text DEFAULT NULL,
  `actual_text` text DEFAULT NULL,
  `difficulty` enum('very_easy','easy','moderate','hard','very_hard','unknown') NOT NULL DEFAULT 'unknown',
  `evidence_json` longtext DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `security_test_runs`
--

CREATE TABLE `security_test_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `target_id` bigint(20) UNSIGNED NOT NULL,
  `authorization_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `run_type` varchar(80) NOT NULL DEFAULT 'full_safe_review',
  `state` enum('queued','preparing','running','blocked_owner','completed','completed_warning','failed','cancelled') NOT NULL DEFAULT 'queued',
  `current_test_key` varchar(120) DEFAULT NULL,
  `tests_total` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `tests_passed` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `tests_failed` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `tests_blocked` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `findings_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `summary` text DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `service_accounts`
--

CREATE TABLE `service_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `platform` varchar(80) NOT NULL,
  `display_name` varchar(190) NOT NULL,
  `username` varchar(190) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `login_url` varchar(1200) DEFAULT NULL,
  `secret_ref` varchar(190) DEFAULT NULL,
  `owner_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `requested_by_type` varchar(40) NOT NULL DEFAULT 'owner',
  `requested_by_id` varchar(100) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('active','disabled','challenge','error','pending') NOT NULL DEFAULT 'active',
  `metadata_json` longtext DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(160) NOT NULL,
  `value_text` longtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `social_accounts`
--

CREATE TABLE `social_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `platform` enum('youtube','tiktok','whatsapp','telegram','facebook','instagram','x','other') NOT NULL,
  `external_ref` varchar(190) NOT NULL DEFAULT '',
  `display_name` varchar(190) NOT NULL,
  `owner_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('active','disabled','error','reauth_required') NOT NULL DEFAULT 'active',
  `metadata_json` longtext DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `social_account_runs`
--

CREATE TABLE `social_account_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `service_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `playbook_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `platform` varchar(80) NOT NULL,
  `operation_key` varchar(80) NOT NULL,
  `context_json` longtext DEFAULT NULL,
  `state` enum('queued','running','challenge','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  `current_step` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `evidence_json` longtext DEFAULT NULL,
  `challenge_text` varchar(1000) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `social_contacts`
--

CREATE TABLE `social_contacts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source_platform` enum('youtube','tiktok','whatsapp','telegram','facebook','instagram','x','other') NOT NULL DEFAULT 'other',
  `external_ref` varchar(190) DEFAULT NULL,
  `display_name` varchar(190) DEFAULT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `username` varchar(190) DEFAULT NULL,
  `member_role` varchar(120) DEFAULT NULL,
  `member_status` enum('lead','member','creator','supervisor','inactive','blocked','unknown') NOT NULL DEFAULT 'unknown',
  `tags_json` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `owner_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_interaction_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `social_content`
--

CREATE TABLE `social_content` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_by_agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `content_type` enum('text','image','video','short','reel','story') NOT NULL DEFAULT 'text',
  `title` varchar(220) DEFAULT NULL,
  `caption` longtext DEFAULT NULL,
  `prompt_text` longtext DEFAULT NULL,
  `asset_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('draft','approved','scheduled','publishing','published','failed') NOT NULL DEFAULT 'draft',
  `owner_approved` tinyint(1) NOT NULL DEFAULT 0,
  `approved_at` datetime DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `social_interactions`
--

CREATE TABLE `social_interactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `owner_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `platform` enum('youtube','tiktok','whatsapp','telegram','facebook','instagram','other') NOT NULL DEFAULT 'other',
  `direction` enum('inbound','outbound','system') NOT NULL DEFAULT 'system',
  `interaction_type` enum('message','comment','member_event','publication','note','other') NOT NULL DEFAULT 'message',
  `external_id` varchar(190) DEFAULT NULL,
  `body_text` longtext DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `happened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `social_metrics`
--

CREATE TABLE `social_metrics` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `publication_id` bigint(20) UNSIGNED DEFAULT NULL,
  `account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `platform` varchar(40) NOT NULL,
  `views_count` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `likes_count` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `comments_count` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `shares_count` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `followers_count` bigint(20) UNSIGNED DEFAULT NULL,
  `raw_json` longtext DEFAULT NULL,
  `captured_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `social_playbooks`
--

CREATE TABLE `social_playbooks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `platform` varchar(80) NOT NULL,
  `operation_key` varchar(80) NOT NULL,
  `version_no` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `label_ar` varchar(220) NOT NULL,
  `state` enum('draft','approved','disabled') NOT NULL DEFAULT 'draft',
  `owner_approved` tinyint(1) NOT NULL DEFAULT 0,
  `config_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `social_playbook_steps`
--

CREATE TABLE `social_playbook_steps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `playbook_id` bigint(20) UNSIGNED NOT NULL,
  `step_no` int(10) UNSIGNED NOT NULL,
  `action_type` varchar(60) NOT NULL,
  `selector_text` varchar(1200) DEFAULT NULL,
  `value_template` text DEFAULT NULL,
  `expected_text` text DEFAULT NULL,
  `challenge_policy` varchar(60) NOT NULL DEFAULT 'stop_and_notify',
  `evidence_policy` varchar(60) NOT NULL DEFAULT 'screenshot_and_url',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `social_playbook_step_runs`
--

CREATE TABLE `social_playbook_step_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `step_no` int(10) UNSIGNED NOT NULL,
  `action_type` varchar(80) NOT NULL,
  `state` enum('running','passed','failed','challenge','blocked') NOT NULL DEFAULT 'running',
  `expected_text` varchar(4000) DEFAULT NULL,
  `actual_text` text DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `error_code` varchar(220) DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `social_publications`
--

CREATE TABLE `social_publications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `content_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `requested_by_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `platform` enum('youtube','tiktok','whatsapp','telegram','facebook','instagram','x','other') NOT NULL,
  `state` enum('queued','publishing','published','failed') NOT NULL DEFAULT 'queued',
  `owner_approved` tinyint(1) NOT NULL DEFAULT 0,
  `approved_at` datetime DEFAULT NULL,
  `job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_checked_at` datetime DEFAULT NULL,
  `external_post_id` varchar(220) DEFAULT NULL,
  `external_url` varchar(1200) DEFAULT NULL,
  `provider_response_json` longtext DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `error_code` varchar(190) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `system_archives`
--

CREATE TABLE `system_archives` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `data_key` varchar(100) NOT NULL,
  `source_table` varchar(100) NOT NULL,
  `source_id` varchar(190) NOT NULL,
  `payload_json` longtext NOT NULL,
  `source_created_at` datetime DEFAULT NULL,
  `archived_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `system_errors`
--

CREATE TABLE `system_errors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `error_ref` varchar(32) NOT NULL,
  `request_uri` varchar(1200) DEFAULT NULL,
  `http_method` varchar(12) DEFAULT NULL,
  `error_class` varchar(190) DEFAULT NULL,
  `error_message` varchar(1000) DEFAULT NULL,
  `actor_type` varchar(40) DEFAULT NULL,
  `actor_id` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `tasks`
--

CREATE TABLE `tasks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` char(18) NOT NULL,
  `parent_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `requested_by_type` enum('owner','agent','customer','system') NOT NULL DEFAULT 'owner',
  `requested_by_id` varchar(100) DEFAULT NULL,
  `created_by_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(220) NOT NULL,
  `description` longtext NOT NULL,
  `context_json` longtext DEFAULT NULL,
  `priority` enum('low','normal','high','critical') NOT NULL DEFAULT 'normal',
  `status` enum('new','queued','assigned','working','waiting','blocked','needs_review','review_failed','needs_fix','retesting','approved','completed','cancelled','failed') NOT NULL DEFAULT 'new',
  `review_status` enum('not_required','pending','approved','approved_warning','rejected','blocked') NOT NULL DEFAULT 'pending',
  `owner_authorized` tinyint(1) NOT NULL DEFAULT 0,
  `destructive` tinyint(1) NOT NULL DEFAULT 0,
  `current_attempt` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 4,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `task_dependencies`
--

CREATE TABLE `task_dependencies` (
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `depends_on_task_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `task_events`
--

CREATE TABLE `task_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `actor_type` enum('owner','agent','customer','system') NOT NULL,
  `actor_id` varchar(100) DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `task_evidence`
--

CREATE TABLE `task_evidence` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `evidence_type` varchar(80) NOT NULL,
  `label` varchar(220) NOT NULL,
  `state` enum('captured','verified','failed','warning') NOT NULL DEFAULT 'captured',
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `team_chat_messages`
--

CREATE TABLE `team_chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sender_type` enum('owner','agent','system') NOT NULL,
  `sender_ref` varchar(120) NOT NULL,
  `receiver_ref` varchar(120) NOT NULL DEFAULT 'team',
  `message_kind` enum('command','reply','status','task','result','note') NOT NULL DEFAULT 'note',
  `body_text` longtext NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `full_name` varchar(160) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('owner','admin') NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `video_audio_assets`
--

CREATE TABLE `video_audio_assets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_id` bigint(20) UNSIGNED NOT NULL,
  `scene_id` bigint(20) UNSIGNED DEFAULT NULL,
  `audio_type` enum('voiceover','music','sfx','mix') NOT NULL,
  `voice_key` varchar(120) DEFAULT NULL,
  `text_content` text DEFAULT NULL,
  `project_asset_id` bigint(20) UNSIGNED DEFAULT NULL,
  `duration_ms` int(10) UNSIGNED DEFAULT NULL,
  `state` enum('planned','queued','processing','ready','failed') NOT NULL DEFAULT 'planned',
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `video_continuity_assets`
--

CREATE TABLE `video_continuity_assets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_id` bigint(20) UNSIGNED NOT NULL,
  `asset_type` enum('character','face_reference','location','costume','style','color','other') NOT NULL,
  `label` varchar(220) NOT NULL,
  `project_asset_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reference_url` varchar(1200) DEFAULT NULL,
  `seed_value` varchar(190) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `video_productions`
--

CREATE TABLE `video_productions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_by_agent_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(220) NOT NULL,
  `concept` longtext NOT NULL,
  `cinematic_prompt` longtext NOT NULL,
  `duration_seconds` int(10) UNSIGNED NOT NULL DEFAULT 30,
  `aspect_ratio` varchar(20) NOT NULL DEFAULT '16:9',
  `target_platform` varchar(40) NOT NULL DEFAULT 'youtube',
  `state` enum('draft','generating','ready','approved','publishing','published','failed') NOT NULL DEFAULT 'draft',
  `media_request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `asset_id` bigint(20) UNSIGNED DEFAULT NULL,
  `owner_approved` tinyint(1) NOT NULL DEFAULT 0,
  `approved_at` datetime DEFAULT NULL,
  `youtube_video_id` varchar(190) DEFAULT NULL,
  `error_code` varchar(190) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `video_render_jobs`
--

CREATE TABLE `video_render_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `render_strategy` varchar(80) NOT NULL DEFAULT 'media_bridge_compose',
  `media_request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` enum('queued','rendering','completed','failed') NOT NULL DEFAULT 'queued',
  `result_asset_id` bigint(20) UNSIGNED DEFAULT NULL,
  `manifest_json` longtext DEFAULT NULL,
  `error_code` varchar(220) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `video_scenes`
--

CREATE TABLE `video_scenes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_id` bigint(20) UNSIGNED NOT NULL,
  `scene_no` int(10) UNSIGNED NOT NULL,
  `title` varchar(220) NOT NULL,
  `duration_seconds` int(10) UNSIGNED NOT NULL DEFAULT 5,
  `prompt_text` longtext NOT NULL,
  `voice_text` text DEFAULT NULL,
  `camera_text` text DEFAULT NULL,
  `continuity_json` longtext DEFAULT NULL,
  `state` enum('planned','queued','generating','ready','failed','approved') NOT NULL DEFAULT 'planned',
  `approval_required` tinyint(1) NOT NULL DEFAULT 0,
  `approved_at` datetime DEFAULT NULL,
  `media_request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `asset_id` bigint(20) UNSIGNED DEFAULT NULL,
  `error_code` varchar(220) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `voice_conversations`
--

CREATE TABLE `voice_conversations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` varchar(32) NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `channel_key` varchar(50) NOT NULL DEFAULT 'dashboard',
  `channel` varchar(60) DEFAULT NULL,
  `direction` varchar(30) DEFAULT NULL,
  `provider_key` varchar(80) NOT NULL DEFAULT 'internal',
  `external_call_id` varchar(190) DEFAULT NULL,
  `state` varchar(40) NOT NULL DEFAULT 'active',
  `transcript_text` longtext DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `error_code` varchar(190) DEFAULT NULL,
  `last_turn_at` datetime DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `voice_turns`
--

CREATE TABLE `voice_turns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `turn_no` int(10) UNSIGNED NOT NULL,
  `direction` varchar(40) NOT NULL DEFAULT 'owner_to_agent',
  `speaker` varchar(30) DEFAULT NULL,
  `input_type` varchar(30) DEFAULT NULL,
  `input_text` longtext DEFAULT NULL,
  `text_content` longtext DEFAULT NULL,
  `transcript` longtext DEFAULT NULL,
  `output_text` longtext DEFAULT NULL,
  `response_text` longtext DEFAULT NULL,
  `input_audio_path` varchar(500) DEFAULT NULL,
  `input_path_ref` varchar(1000) DEFAULT NULL,
  `audio_ref` varchar(700) DEFAULT NULL,
  `output_audio_path` varchar(500) DEFAULT NULL,
  `output_path_ref` varchar(1000) DEFAULT NULL,
  `stt_provider` varchar(80) DEFAULT NULL,
  `tts_provider` varchar(80) DEFAULT NULL,
  `provider_key` varchar(100) DEFAULT NULL,
  `model` varchar(190) DEFAULT NULL,
  `state` varchar(40) NOT NULL DEFAULT 'completed',
  `metadata_json` longtext DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `webhook_events`
--

CREATE TABLE `webhook_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `provider` varchar(80) NOT NULL,
  `external_event_id` varchar(190) DEFAULT NULL,
  `signature_valid` tinyint(1) NOT NULL DEFAULT 0,
  `event_type` varchar(100) DEFAULT NULL,
  `processing_state` enum('received','processed','ignored','failed') NOT NULL DEFAULT 'received',
  `payload_json` longtext DEFAULT NULL,
  `error_code` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `whatsapp_call_sessions`
--

CREATE TABLE `whatsapp_call_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `provider_call_id` varchar(220) NOT NULL,
  `direction` enum('inbound','outbound') NOT NULL,
  `from_ref` varchar(120) DEFAULT NULL,
  `to_ref` varchar(120) DEFAULT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `state` enum('offered','permission_required','ringing','connected','completed','rejected','failed','blocked') NOT NULL DEFAULT 'offered',
  `bridge_session_id` varchar(220) DEFAULT NULL,
  `signaling_json` longtext DEFAULT NULL,
  `raw_json` longtext DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `whatsapp_pending_messages`
--

CREATE TABLE `whatsapp_pending_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `body_text` longtext NOT NULL,
  `body_hash` char(64) NOT NULL,
  `reason` varchar(80) NOT NULL DEFAULT 'outside_window',
  `state` enum('pending','waiting_reply','sending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  `attempts` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `last_error` varchar(190) DEFAULT NULL,
  `template_message_id` varchar(190) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- بنية الجدول `worker_heartbeat_log`
--

CREATE TABLE `worker_heartbeat_log` (
  `heartbeat_minute` datetime NOT NULL,
  `hit_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `last_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


--
-- Indexes for dumped tables
--

--
-- فهارس للجدول `agency_creators`
--
ALTER TABLE `agency_creators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agency_creator_id` (`creator_id`),
  ADD KEY `idx_agency_creator_handle` (`handle`),
  ADD KEY `idx_agency_creator_supervisor` (`supervisor_contact_id`,`active`);

--
-- فهارس للجدول `agency_creator_monthly`
--
ALTER TABLE `agency_creator_monthly`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agency_month_source` (`creator_id`,`data_month`,`source_type`),
  ADD KEY `idx_agency_month` (`data_month`,`diamonds`),
  ADD KEY `idx_agency_creator_month` (`creator_id`,`data_month`);

--
-- فهارس للجدول `agency_creator_policies`
--
ALTER TABLE `agency_creator_policies`
  ADD PRIMARY KEY (`creator_id`);

--
-- فهارس للجدول `agency_events`
--
ALTER TABLE `agency_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agency_events_due` (`state`,`due_at`),
  ADD KEY `idx_agency_events_creator` (`creator_id`,`created_at`),
  ADD KEY `idx_agency_events_supervisor` (`supervisor_contact_id`,`state`),
  ADD KEY `idx_agency_events_reminder` (`state`,`remind_at`),
  ADD KEY `idx_agency_events_dedupe` (`dedupe_key`,`state`);

--
-- فهارس للجدول `agency_imports`
--
ALTER TABLE `agency_imports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agency_import_hash` (`file_sha256`),
  ADD KEY `idx_agency_import_month` (`data_month`,`import_type`);

--
-- فهارس للجدول `agency_threads`
--
ALTER TABLE `agency_threads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agency_threads_contact` (`contact_id`,`state`,`last_message_at`),
  ADD KEY `idx_agency_threads_creator` (`creator_id`,`state`);

--
-- فهارس للجدول `agency_thread_messages`
--
ALTER TABLE `agency_thread_messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agency_message_external` (`external_id`),
  ADD KEY `idx_agency_thread_messages` (`thread_id`,`created_at`);

--
-- فهارس للجدول `agents`
--
ALTER TABLE `agents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `fk_agents_manager` (`manager_id`),
  ADD KEY `idx_agents_current_task` (`current_task_id`),
  ADD KEY `idx_agents_runtime` (`status`,`last_seen_at`);

--
-- فهارس للجدول `agent_autonomy`
--
ALTER TABLE `agent_autonomy`
  ADD PRIMARY KEY (`agent_id`),
  ADD KEY `idx_agent_autonomy_due` (`initiative_enabled`,`next_run_at`);

--
-- فهارس للجدول `agent_budget_policies`
--
ALTER TABLE `agent_budget_policies`
  ADD PRIMARY KEY (`agent_id`);

--
-- فهارس للجدول `agent_budget_reservations`
--
ALTER TABLE `agent_budget_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_budget_reservation_agent` (`agent_id`,`capability`,`state`,`expires_at`),
  ADD KEY `idx_budget_reservation_ref` (`reference_key`);

--
-- فهارس للجدول `agent_builder_profiles`
--
ALTER TABLE `agent_builder_profiles`
  ADD PRIMARY KEY (`agent_id`);

--
-- فهارس للجدول `agent_capability_assignments`
--
ALTER TABLE `agent_capability_assignments`
  ADD PRIMARY KEY (`agent_id`,`capability_key`);

--
-- فهارس للجدول `agent_capability_catalog`
--
ALTER TABLE `agent_capability_catalog`
  ADD PRIMARY KEY (`capability_key`);

--
-- فهارس للجدول `agent_capability_route_preferences`
--
ALTER TABLE `agent_capability_route_preferences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_cap_route_pref` (`agent_id`,`capability`,`enabled`,`priority_order`);

--
-- فهارس للجدول `agent_change_proposals`
--
ALTER TABLE `agent_change_proposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_change_proposals_state` (`state`,`risk_level`,`created_at`),
  ADD KEY `idx_change_proposals_agent` (`agent_id`,`created_at`);

--
-- فهارس للجدول `agent_change_rollbacks`
--
ALTER TABLE `agent_change_rollbacks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_change_rollback_proposal` (`proposal_id`);

--
-- فهارس للجدول `agent_channel_permissions`
--
ALTER TABLE `agent_channel_permissions`
  ADD PRIMARY KEY (`agent_id`,`channel_key`);

--
-- فهارس للجدول `agent_data_fields`
--
ALTER TABLE `agent_data_fields`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_data_field` (`schema_id`,`field_key`),
  ADD KEY `idx_agent_data_field_sort` (`schema_id`,`sort_order`);

--
-- فهارس للجدول `agent_data_rows`
--
ALTER TABLE `agent_data_rows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_data_rows_schema` (`schema_id`,`id`),
  ADD KEY `idx_agent_data_rows_agent_project` (`agent_id`,`project_id`,`id`),
  ADD KEY `fk_agent_data_row_project` (`project_id`);

--
-- فهارس للجدول `agent_data_schemas`
--
ALTER TABLE `agent_data_schemas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_data_schema` (`agent_id`,`schema_key`),
  ADD KEY `idx_agent_data_schema_active` (`agent_id`,`is_active`);

--
-- فهارس للجدول `agent_external_actions`
--
ALTER TABLE `agent_external_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_external_actions_agent` (`agent_id`,`state`,`created_at`),
  ADD KEY `idx_external_actions_contact` (`contact_id`,`state`);

--
-- فهارس للجدول `agent_followups`
--
ALTER TABLE `agent_followups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_followups_due` (`state`,`due_at`),
  ADD KEY `idx_followups_agent` (`agent_id`,`state`);

--
-- فهارس للجدول `agent_goal_contributions`
--
ALTER TABLE `agent_goal_contributions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_goal_contrib17` (`goal_id`,`created_at`),
  ADD KEY `idx_goal_contrib_agent17` (`agent_id`,`created_at`),
  ADD KEY `idx_goal_contrib_initiative17` (`initiative_id`);

--
-- فهارس للجدول `agent_initiatives`
--
ALTER TABLE `agent_initiatives`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_initiatives_agent` (`agent_id`,`state`,`created_at`),
  ADD KEY `idx_agent_initiatives_task` (`task_id`),
  ADD KEY `idx_initiatives_dedupe17` (`agent_id`,`dedupe_key`,`state`),
  ADD KEY `idx_initiatives_goal17` (`goal_id`,`state`,`created_at`),
  ADD KEY `idx_initiatives_dedupe19` (`agent_id`,`dedupe_key`,`state`),
  ADD KEY `idx_initiatives_goal19` (`goal_id`,`state`,`created_at`);

--
-- فهارس للجدول `agent_kpi_daily`
--
ALTER TABLE `agent_kpi_daily`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_kpi_day` (`agent_id`,`kpi_date`,`kpi_key`),
  ADD KEY `idx_kpi_daily_date` (`kpi_date`,`kpi_key`);

--
-- فهارس للجدول `agent_kpi_definitions`
--
ALTER TABLE `agent_kpi_definitions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kpi_def` (`agent_id`,`kpi_key`),
  ADD KEY `idx_kpi_def_key` (`kpi_key`,`active`);

--
-- فهارس للجدول `agent_learning`
--
ALTER TABLE `agent_learning`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_learning_agent` (`agent_id`,`status`,`created_at`),
  ADD KEY `idx_agent_learning_project` (`project_id`),
  ADD KEY `idx_agent_learning_task` (`source_task_id`);

--
-- فهارس للجدول `agent_memory`
--
ALTER TABLE `agent_memory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_memory` (`agent_id`,`memory_type`,`content_hash`),
  ADD KEY `fk_agent_memory_project` (`project_id`);

--
-- فهارس للجدول `agent_memory_bank`
--
ALTER TABLE `agent_memory_bank`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_memory_bank_agent` (`agent_id`,`active`,`memory_kind`,`salience`),
  ADD KEY `idx_memory_bank_project` (`project_id`,`active`),
  ADD KEY `idx_memory_bank_entity` (`agent_id`,`entity_key`),
  ADD KEY `idx_memory_bank_source` (`source_type`,`source_id`);

--
-- فهارس للجدول `agent_memory_links`
--
ALTER TABLE `agent_memory_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_memory_link` (`from_memory_id`,`to_memory_id`,`relation_key`),
  ADD KEY `idx_memory_link_to` (`to_memory_id`);

--
-- فهارس للجدول `agent_performance_snapshots`
--
ALTER TABLE `agent_performance_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_perf_period` (`agent_id`,`period_start`,`period_end`);

--
-- فهارس للجدول `agent_permissions`
--
ALTER TABLE `agent_permissions`
  ADD PRIMARY KEY (`agent_id`,`permission_key`),
  ADD KEY `permission_key` (`permission_key`);

--
-- فهارس للجدول `agent_permission_sources`
--
ALTER TABLE `agent_permission_sources`
  ADD PRIMARY KEY (`agent_id`,`permission_key`,`source_type`,`source_key`),
  ADD KEY `idx_agent_permission_sources_perm` (`agent_id`,`permission_key`);

--
-- فهارس للجدول `agent_policy_decisions`
--
ALTER TABLE `agent_policy_decisions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_policy_agent` (`agent_id`,`created_at`),
  ADD KEY `idx_policy_capability` (`capability_key`,`decision`,`created_at`),
  ADD KEY `idx_policy_action17` (`action_key`,`created_at`),
  ADD KEY `idx_policy_state17` (`decision_state`,`created_at`);

--
-- فهارس للجدول `agent_policy_rules`
--
ALTER TABLE `agent_policy_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_policy_action17` (`action_pattern`);

--
-- فهارس للجدول `agent_project_access`
--
ALTER TABLE `agent_project_access`
  ADD PRIMARY KEY (`agent_id`,`project_id`),
  ADD KEY `fk_agent_project_access_project` (`project_id`);

--
-- فهارس للجدول `agent_prompt_versions`
--
ALTER TABLE `agent_prompt_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_prompt_version` (`agent_id`,`version_no`),
  ADD KEY `idx_agent_prompt_proposal` (`proposal_id`);

--
-- فهارس للجدول `agent_provider_routes`
--
ALTER TABLE `agent_provider_routes`
  ADD PRIMARY KEY (`agent_id`,`route_order`),
  ADD KEY `idx_agent_provider_route_provider` (`provider_key`,`enabled`);

--
-- فهارس للجدول `agent_relationships`
--
ALTER TABLE `agent_relationships`
  ADD PRIMARY KEY (`from_agent_id`,`to_agent_id`),
  ADD KEY `to_agent_id` (`to_agent_id`);

--
-- فهارس للجدول `agent_role_templates`
--
ALTER TABLE `agent_role_templates`
  ADD PRIMARY KEY (`template_key`);

--
-- فهارس للجدول `agent_runs`
--
ALTER TABLE `agent_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `fk_agent_runs_task` (`task_id`);

--
-- فهارس للجدول `agent_schema_requests`
--
ALTER TABLE `agent_schema_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_schema_table17` (`table_name`);

--
-- فهارس للجدول `agent_tools`
--
ALTER TABLE `agent_tools`
  ADD PRIMARY KEY (`agent_id`,`tool_key`);

--
-- فهارس للجدول `agent_tool_sources`
--
ALTER TABLE `agent_tool_sources`
  ADD PRIMARY KEY (`agent_id`,`tool_key`,`source_type`,`source_key`),
  ADD KEY `idx_agent_tool_sources_tool` (`agent_id`,`tool_key`);

--
-- فهارس للجدول `agent_usage_daily`
--
ALTER TABLE `agent_usage_daily`
  ADD PRIMARY KEY (`agent_id`,`usage_date`),
  ADD KEY `idx_agent_usage_date17` (`usage_date`);

--
-- فهارس للجدول `agent_usage_events`
--
ALTER TABLE `agent_usage_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_usage_event17` (`agent_id`,`created_at`),
  ADD KEY `idx_agent_usage_type17` (`usage_type`,`created_at`);

--
-- فهارس للجدول `agent_usage_ledger`
--
ALTER TABLE `agent_usage_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_usage_agent_date` (`agent_id`,`created_at`),
  ADD KEY `idx_usage_provider` (`provider_key`,`model_key`,`created_at`);

--
-- فهارس للجدول `agent_workflow_assignments`
--
ALTER TABLE `agent_workflow_assignments`
  ADD PRIMARY KEY (`agent_id`,`workflow_id`);

--
-- فهارس للجدول `agent_workflow_runs`
--
ALTER TABLE `agent_workflow_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_workflow_runs_agent` (`agent_id`,`state`,`id`);

--
-- فهارس للجدول `agent_workflow_run_steps`
--
ALTER TABLE `agent_workflow_run_steps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_workflow_step` (`run_id`,`step_no`);

--
-- فهارس للجدول `agent_workflow_steps`
--
ALTER TABLE `agent_workflow_steps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_workflow_step17` (`workflow_id`,`step_no`);

--
-- فهارس للجدول `agent_workflow_templates`
--
ALTER TABLE `agent_workflow_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_workflow_key` (`workflow_key`),
  ADD UNIQUE KEY `uq_agent_workflow_key17` (`workflow_key`);

--
-- فهارس للجدول `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `idx_audit_created` (`created_at`),
  ADD KEY `idx_audit_project` (`project_id`,`created_at`);

--
-- فهارس للجدول `backups`
--
ALTER TABLE `backups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `task_id` (`task_id`);

--
-- فهارس للجدول `calls`
--
ALTER TABLE `calls`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_provider_call` (`provider_call_id`),
  ADD KEY `conversation_id` (`conversation_id`),
  ADD KEY `channel_id` (`channel_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `task_id` (`task_id`);

--
-- فهارس للجدول `change_requests`
--
ALTER TABLE `change_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_change_task` (`task_id`);

--
-- فهارس للجدول `cloud_objects`
--
ALTER TABLE `cloud_objects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cloud_provider_state` (`provider_key`,`state`),
  ADD KEY `idx_cloud_checksum` (`checksum`);

--
-- فهارس للجدول `communication_channels`
--
ALTER TABLE `communication_channels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `channel_key` (`channel_key`);

--
-- فهارس للجدول `communication_events`
--
ALTER TABLE `communication_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `channel_id` (`channel_id`),
  ADD KEY `message_id` (`message_id`);

--
-- فهارس للجدول `company_goals`
--
ALTER TABLE `company_goals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_goals_state` (`state`,`priority`,`due_at`);

--
-- فهارس للجدول `company_goal_agents`
--
ALTER TABLE `company_goal_agents`
  ADD PRIMARY KEY (`goal_id`,`agent_id`);

--
-- فهارس للجدول `company_goal_events`
--
ALTER TABLE `company_goal_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_goal_events_goal` (`goal_id`,`created_at`),
  ADD KEY `idx_goal_events_agent` (`agent_id`,`created_at`);

--
-- فهارس للجدول `company_memory`
--
ALTER TABLE `company_memory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `content_hash` (`content_hash`);

--
-- فهارس للجدول `connection_tests`
--
ALTER TABLE `connection_tests`
  ADD PRIMARY KEY (`id`);

--
-- فهارس للجدول `contact_communication_policies`
--
ALTER TABLE `contact_communication_policies`
  ADD PRIMARY KEY (`contact_id`);

--
-- فهارس للجدول `contact_directory`
--
ALTER TABLE `contact_directory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contact_directory_role` (`role_title`,`enabled`),
  ADD KEY `idx_contact_directory_group` (`group_key`,`enabled`);

--
-- فهارس للجدول `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `session_id` (`session_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `idx_thread` (`provider`,`external_thread_id`);

--
-- فهارس للجدول `conversation_sessions`
--
ALTER TABLE `conversation_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_subject_status` (`subject_type`,`subject_key`,`status`),
  ADD KEY `active_project_id` (`active_project_id`),
  ADD KEY `active_task_id` (`active_task_id`),
  ADD KEY `last_agent_id` (`last_agent_id`);

--
-- فهارس للجدول `creator_intelligence_snapshots`
--
ALTER TABLE `creator_intelligence_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_creator_intelligence17` (`creator_id`,`data_month`),
  ADD KEY `idx_creator_intelligence_attention17` (`data_month`,`attention_score`);

--
-- فهارس للجدول `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer_phone` (`phone`),
  ADD KEY `idx_customer_email` (`email`);

--
-- فهارس للجدول `customer_memory`
--
ALTER TABLE `customer_memory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_customer_memory_hash` (`customer_id`,`content_hash`),
  ADD KEY `idx_customer_memory_active` (`customer_id`,`active`,`importance`,`updated_at`);

--
-- فهارس للجدول `customer_projects`
--
ALTER TABLE `customer_projects`
  ADD PRIMARY KEY (`customer_id`,`project_id`),
  ADD KEY `project_id` (`project_id`);

--
-- فهارس للجدول `data_retention_policies`
--
ALTER TABLE `data_retention_policies`
  ADD PRIMARY KEY (`data_key`);

--
-- فهارس للجدول `data_source_registry`
--
ALTER TABLE `data_source_registry`
  ADD PRIMARY KEY (`source_key`),
  ADD KEY `idx_data_source_concept` (`concept_key`,`status`);

--
-- فهارس للجدول `deployments`
--
ALTER TABLE `deployments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `task_id` (`task_id`);

--
-- فهارس للجدول `free_model_catalog`
--
ALTER TABLE `free_model_catalog`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_free_model_provider_model` (`provider_key`,`model_key`),
  ADD KEY `idx_free_model_health` (`active`,`health_state`,`score`);

--
-- فهارس للجدول `free_model_scout_runs`
--
ALTER TABLE `free_model_scout_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_free_scout_runs_state` (`state`,`started_at`);

--
-- فهارس للجدول `free_provider_candidates`
--
ALTER TABLE `free_provider_candidates`
  ADD PRIMARY KEY (`provider_key`),
  ADD KEY `idx_free_provider_state` (`state`,`auto_route`,`priority_order`);

--
-- فهارس للجدول `goal_metric_definitions`
--
ALTER TABLE `goal_metric_definitions`
  ADD PRIMARY KEY (`metric_key`);

--
-- فهارس للجدول `inbound_message_claims`
--
ALTER TABLE `inbound_message_claims`
  ADD PRIMARY KEY (`provider`,`external_id`),
  ADD KEY `idx_inbound_claim_state` (`state`,`updated_at`);

--
-- فهارس للجدول `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `idx_jobs_queue` (`state`,`available_at`,`priority`),
  ADD KEY `idx_jobs_lease` (`state`,`lease_expires_at`),
  ADD KEY `idx_jobs_retry` (`state`,`next_retry_at`),
  ADD KEY `idx_jobs_agent_finished` (`agent_id`,`finished_at`);

--
-- فهارس للجدول `lab_environments`
--
ALTER TABLE `lab_environments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lab_project` (`project_id`),
  ADD UNIQUE KEY `uq_lab_domain` (`full_domain`),
  ADD KEY `idx_lab_state` (`state`);

--
-- فهارس للجدول `media_requests`
--
ALTER TABLE `media_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_media_idempotency` (`idempotency_key`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `requested_by_agent_id` (`requested_by_agent_id`),
  ADD KEY `result_asset_id` (`result_asset_id`),
  ADD KEY `idx_media_requests` (`project_id`,`state`,`created_at`),
  ADD KEY `idx_media_pending` (`state`,`next_poll_at`);

--
-- فهارس للجدول `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_external` (`provider`,`external_id`),
  ADD KEY `conversation_id` (`conversation_id`),
  ADD KEY `idx_session_messages` (`session_id`,`id`);

--
-- فهارس للجدول `migration_preflight_runs`
--
ALTER TABLE `migration_preflight_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_migration_preflight_version` (`target_version`,`state`,`created_at`);

--
-- فهارس للجدول `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_unread` (`read_at`,`created_at`);

--
-- فهارس للجدول `opportunities`
--
ALTER TABLE `opportunities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_opportunity_status` (`status`,`score`),
  ADD KEY `idx_opportunity_agent` (`discovered_by_agent_id`),
  ADD KEY `idx_opportunity_customer` (`customer_id`),
  ADD KEY `fk_opportunity_project` (`project_id`),
  ADD KEY `fk_opportunity_quote` (`quote_id`),
  ADD KEY `idx_opportunities_fingerprint` (`fingerprint`),
  ADD KEY `idx_opportunities_published` (`published_at`),
  ADD KEY `idx_opportunities_fit_status` (`fit_status`,`status`,`score`),
  ADD KEY `idx_opportunities_shortlist` (`shortlist_rank`,`score`),
  ADD KEY `idx_opportunities_market_score` (`market_scope`,`score`,`status`),
  ADD KEY `idx_opportunities_contact_score` (`intent_confidence`,`source_quality`,`score`),
  ADD KEY `idx_opportunities_score_v` (`score_version`,`score`),
  ADD KEY `idx_opportunities_contact_verified` (`contact_verified_at`);

--
-- فهارس للجدول `opportunity_cost_observations`
--
ALTER TABLE `opportunity_cost_observations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_opp_cost_opportunity` (`opportunity_id`),
  ADD KEY `idx_opp_cost_category_currency` (`category_key`,`currency`),
  ADD KEY `idx_opp_cost_type_currency` (`type_key`,`currency`),
  ADD KEY `idx_opp_cost_updated` (`updated_at`);

--
-- فهارس للجدول `opportunity_feedback`
--
ALTER TABLE `opportunity_feedback`
  ADD PRIMARY KEY (`opportunity_id`);

--
-- فهارس للجدول `opportunity_fingerprints`
--
ALTER TABLE `opportunity_fingerprints`
  ADD PRIMARY KEY (`fingerprint`),
  ADD KEY `idx_opportunity_fingerprint_decision` (`decision`,`last_seen_at`);

--
-- فهارس للجدول `opportunity_raw_items`
--
ALTER TABLE `opportunity_raw_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_opportunity_raw_hash_run` (`run_id`,`raw_hash`),
  ADD KEY `idx_opportunity_raw_state` (`processing_state`),
  ADD KEY `idx_opportunity_raw_opp` (`opportunity_id`);

--
-- فهارس للجدول `opportunity_search_runs`
--
ALTER TABLE `opportunity_search_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_opportunity_runs_state` (`state`,`created_at`),
  ADD KEY `idx_opportunity_runs_task` (`task_id`),
  ADD KEY `idx_opportunity_runs_agent` (`agent_id`);

--
-- فهارس للجدول `opportunity_sources`
--
ALTER TABLE `opportunity_sources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_opportunity_source_key` (`source_key`),
  ADD KEY `idx_opportunity_sources_enabled` (`enabled`,`priority`);

--
-- فهارس للجدول `owner_channel_routes`
--
ALTER TABLE `owner_channel_routes`
  ADD PRIMARY KEY (`channel_key`),
  ADD KEY `fk_owner_route_agent` (`agent_id`),
  ADD KEY `fk_owner_route_session` (`session_id`),
  ADD KEY `idx_owner_route_state` (`state`,`expires_at`);

--
-- فهارس للجدول `owner_decision_requests`
--
ALTER TABLE `owner_decision_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner_decision_state` (`state`,`created_at`),
  ADD KEY `idx_owner_decision_dedupe` (`dedupe_key`,`state`,`created_at`);

--
-- فهارس للجدول `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `quote_id` (`quote_id`),
  ADD KEY `confirmed_by` (`confirmed_by`);

--
-- فهارس للجدول `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`);

--
-- فهارس للجدول `pending_actions`
--
ALTER TABLE `pending_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pending_session` (`session_id`,`state`,`expires_at`);

--
-- فهارس للجدول `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`permission_key`);

--
-- فهارس للجدول `physical_schema_requests`
--
ALTER TABLE `physical_schema_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_physical_schema_table_request` (`agent_id`,`table_name`,`state`),
  ADD KEY `idx_physical_schema_state` (`state`,`created_at`);

--
-- فهارس للجدول `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uid` (`uid`),
  ADD UNIQUE KEY `uq_primary_domain` (`primary_domain`),
  ADD KEY `idx_projects_workflow_stage` (`workflow_stage`),
  ADD KEY `idx_projects_opportunity` (`opportunity_id`);

--
-- فهارس للجدول `project_assets`
--
ALTER TABLE `project_assets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_assets` (`project_id`,`asset_type`,`status`);

--
-- فهارس للجدول `project_briefs`
--
ALTER TABLE `project_briefs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_project_brief_version` (`project_id`,`version_no`),
  ADD KEY `idx_project_brief_current` (`project_id`,`is_current`);

--
-- فهارس للجدول `project_chat_messages`
--
ALTER TABLE `project_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_chat_project` (`project_id`,`id`),
  ADD KEY `idx_project_chat_kind` (`project_id`,`message_kind`,`pinned`);

--
-- فهارس للجدول `project_cost_entries`
--
ALTER TABLE `project_cost_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_cost_project` (`project_id`,`currency`,`status`),
  ADD KEY `idx_project_cost_task` (`task_id`),
  ADD KEY `fk_project_cost_user` (`created_by`);

--
-- فهارس للجدول `project_databases`
--
ALTER TABLE `project_databases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_project_db` (`project_id`,`db_name`),
  ADD KEY `idx_project_databases_environment` (`project_id`,`environment`),
  ADD KEY `idx_project_databases_source` (`source_database_id`);

--
-- فهارس للجدول `project_domains`
--
ALTER TABLE `project_domains`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `domain_name` (`domain_name`),
  ADD KEY `project_id` (`project_id`);

--
-- فهارس للجدول `project_files`
--
ALTER TABLE `project_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_project_path` (`project_id`,`path`),
  ADD KEY `idx_project_files_important` (`project_id`,`is_important`),
  ADD KEY `idx_project_files_scan` (`project_id`,`last_scan_id`,`status`);

--
-- فهارس للجدول `project_issues`
--
ALTER TABLE `project_issues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `source_agent_id` (`source_agent_id`),
  ADD KEY `idx_project_issues` (`project_id`,`status`,`severity`),
  ADD KEY `idx_project_issues_blocking` (`project_id`,`blocking_delivery`,`status`),
  ADD KEY `idx_project_issues_fix_task` (`fix_task_id`),
  ADD KEY `idx_project_issues_retest` (`retest_review_id`);

--
-- فهارس للجدول `project_memory`
--
ALTER TABLE `project_memory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_project_memory` (`project_id`,`category`,`content_hash`);

--
-- فهارس للجدول `project_scans`
--
ALTER TABLE `project_scans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agent_id` (`agent_id`);

--
-- فهارس للجدول `project_stage_events`
--
ALTER TABLE `project_stage_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_stage_events_project` (`project_id`,`id`);

--
-- فهارس للجدول `prospecting_runs`
--
ALTER TABLE `prospecting_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prospect_runs_state` (`state`,`created_at`),
  ADD KEY `idx_prospect_runs_agent` (`agent_id`,`created_at`);

--
-- فهارس للجدول `prospect_contact_evidence`
--
ALTER TABLE `prospect_contact_evidence`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prospect_contact` (`lead_id`,`contact_type`,`contact_value`(190)),
  ADD KEY `idx_prospect_contact_lead` (`lead_id`,`confidence`);

--
-- فهارس للجدول `prospect_leads`
--
ALTER TABLE `prospect_leads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prospect_fingerprint` (`fingerprint`),
  ADD KEY `idx_prospect_state_score` (`status`,`score`,`created_at`);

--
-- فهارس للجدول `prospect_prototypes`
--
ALTER TABLE `prospect_prototypes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prospect_prototype_lead` (`lead_id`),
  ADD KEY `idx_prospect_prototype_state` (`state`,`updated_at`),
  ADD KEY `idx_prospect_prototype_project` (`project_id`);

--
-- فهارس للجدول `prospect_website_audits`
--
ALTER TABLE `prospect_website_audits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prospect_audit_lead` (`lead_id`,`created_at`);

--
-- فهارس للجدول `providers`
--
ALTER TABLE `providers`
  ADD PRIMARY KEY (`provider_key`);

--
-- فهارس للجدول `provider_capability_routes`
--
ALTER TABLE `provider_capability_routes`
  ADD PRIMARY KEY (`capability`,`route_order`),
  ADD KEY `idx_provider_cap_routes_provider` (`provider_key`,`enabled`);

--
-- فهارس للجدول `provider_pricing`
--
ALTER TABLE `provider_pricing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_provider_pricing` (`provider_key`,`model_pattern`,`capability`);

--
-- فهارس للجدول `quotes`
--
ALTER TABLE `quotes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `idx_quotes_exception` (`owner_exception_required`,`status`);

--
-- فهارس للجدول `ramy_repair_runs`
--
ALTER TABLE `ramy_repair_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ramy_repair_project` (`project_id`,`id`),
  ADD KEY `idx_ramy_repair_session` (`session_id`,`id`),
  ADD KEY `idx_ramy_repair_state` (`state`,`id`);

--
-- فهارس للجدول `release_history`
--
ALTER TABLE `release_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_release_version` (`version`);

--
-- فهارس للجدول `release_setting_snapshots`
--
ALTER TABLE `release_setting_snapshots`
  ADD PRIMARY KEY (`release_key`,`setting_key`);

--
-- فهارس للجدول `resource_registry`
--
ALTER TABLE `resource_registry`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_resource_registry_key` (`resource_type`,`resource_key`),
  ADD UNIQUE KEY `uq_resource_registry17` (`resource_type`,`resource_key`),
  ADD UNIQUE KEY `uq_resource_uid17` (`resource_uid`),
  ADD KEY `idx_resource_registry_agent` (`owner_agent_id`,`state`),
  ADD KEY `idx_resource_registry_project` (`project_id`,`state`);

--
-- فهارس للجدول `resource_registry_events`
--
ALTER TABLE `resource_registry_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_resource_registry_event` (`resource_id`,`created_at`);

--
-- فهارس للجدول `resource_requests`
--
ALTER TABLE `resource_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_resource_requests_state` (`state`,`risk_level`,`created_at`),
  ADD KEY `idx_resource_requests_agent` (`agent_id`,`created_at`);

--
-- فهارس للجدول `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `reviewer_agent_id` (`reviewer_agent_id`);

--
-- فهارس للجدول `review_tests`
--
ALTER TABLE `review_tests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `review_id` (`review_id`);

--
-- فهارس للجدول `runtime_validation_runs`
--
ALTER TABLE `runtime_validation_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_runtime_validation_state` (`scenario_key`,`state`,`created_at`);

--
-- فهارس للجدول `runtime_validation_scenarios`
--
ALTER TABLE `runtime_validation_scenarios`
  ADD PRIMARY KEY (`scenario_key`);

--
-- فهارس للجدول `runtime_validation_steps`
--
ALTER TABLE `runtime_validation_steps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_runtime_validation_steps` (`run_id`,`id`);

--
-- فهارس للجدول `schema_migrations`
--
ALTER TABLE `schema_migrations`
  ADD PRIMARY KEY (`migration_id`),
  ADD KEY `idx_schema_migrations_version` (`version`,`state`);

--
-- فهارس للجدول `schema_migration_steps`
--
ALTER TABLE `schema_migration_steps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_schema_migration_step` (`migration_version`,`step_id`),
  ADD KEY `idx_schema_migration_state` (`migration_version`,`state`,`step_order`);

--
-- فهارس للجدول `schema_table_catalog`
--
ALTER TABLE `schema_table_catalog`
  ADD PRIMARY KEY (`table_name`);

--
-- فهارس للجدول `secure_config_snapshots`
--
ALTER TABLE `secure_config_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_secure_config_snapshot17` (`label`,`envelope_sha256`);

--
-- فهارس للجدول `secure_vault_snapshots`
--
ALTER TABLE `secure_vault_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_secure_vault_sha17` (`envelope_sha256`),
  ADD KEY `idx_secure_vault_state17` (`state`,`created_at`);

--
-- فهارس للجدول `security_authorizations`
--
ALTER TABLE `security_authorizations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_auth_target` (`target_id`,`state`,`valid_until`);

--
-- فهارس للجدول `security_findings`
--
ALTER TABLE `security_findings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_findings_target` (`target_id`,`status`,`severity`),
  ADD KEY `idx_security_findings_project` (`project_id`,`status`),
  ADD KEY `idx_security_findings_run` (`run_id`);

--
-- فهارس للجدول `security_retests`
--
ALTER TABLE `security_retests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_retests_finding` (`finding_id`,`created_at`);

--
-- فهارس للجدول `security_targets`
--
ALTER TABLE `security_targets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_targets_project` (`project_id`,`active`),
  ADD KEY `idx_security_targets_env` (`environment`,`active`);

--
-- فهارس للجدول `security_test_cases`
--
ALTER TABLE `security_test_cases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_security_test_key` (`test_key`),
  ADD KEY `idx_security_test_cases_enabled` (`enabled`,`category`);

--
-- فهارس للجدول `security_test_events`
--
ALTER TABLE `security_test_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_events_run` (`run_id`,`id`),
  ADD KEY `idx_security_events_state` (`state`,`test_key`);

--
-- فهارس للجدول `security_test_runs`
--
ALTER TABLE `security_test_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_runs_target` (`target_id`,`created_at`),
  ADD KEY `idx_security_runs_state` (`state`,`created_at`),
  ADD KEY `idx_security_runs_task` (`task_id`);

--
-- فهارس للجدول `service_accounts`
--
ALTER TABLE `service_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_service_accounts_platform` (`platform`,`status`),
  ADD KEY `idx_service_accounts_agent` (`owner_agent_id`);

--
-- فهارس للجدول `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- فهارس للجدول `social_accounts`
--
ALTER TABLE `social_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_social_account_ref` (`platform`,`external_ref`),
  ADD KEY `idx_social_account_agent` (`owner_agent_id`);

--
-- فهارس للجدول `social_account_runs`
--
ALTER TABLE `social_account_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_social_account_runs` (`state`,`platform`,`created_at`);

--
-- فهارس للجدول `social_contacts`
--
ALTER TABLE `social_contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_social_contact_external` (`source_platform`,`external_ref`),
  ADD KEY `idx_social_contact_phone` (`phone`),
  ADD KEY `idx_social_contact_email` (`email`),
  ADD KEY `idx_social_contact_username` (`source_platform`,`username`),
  ADD KEY `idx_social_contact_agent` (`owner_agent_id`);

--
-- فهارس للجدول `social_content`
--
ALTER TABLE `social_content`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_social_content_agent` (`created_by_agent_id`),
  ADD KEY `idx_social_content_status` (`status`,`scheduled_at`),
  ADD KEY `idx_social_content_project` (`project_id`);

--
-- فهارس للجدول `social_interactions`
--
ALTER TABLE `social_interactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_social_interaction_external` (`platform`,`external_id`),
  ADD KEY `idx_social_interaction_contact` (`contact_id`,`happened_at`),
  ADD KEY `idx_social_interaction_agent` (`owner_agent_id`,`happened_at`),
  ADD KEY `idx_social_interaction_platform` (`platform`,`direction`,`happened_at`);

--
-- فهارس للجدول `social_metrics`
--
ALTER TABLE `social_metrics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_social_metrics_publication` (`publication_id`),
  ADD KEY `idx_social_metrics_account` (`account_id`,`captured_at`);

--
-- فهارس للجدول `social_playbooks`
--
ALTER TABLE `social_playbooks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_social_playbook_version` (`platform`,`operation_key`,`version_no`);

--
-- فهارس للجدول `social_playbook_steps`
--
ALTER TABLE `social_playbook_steps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_social_playbook_step` (`playbook_id`,`step_no`);

--
-- فهارس للجدول `social_playbook_step_runs`
--
ALTER TABLE `social_playbook_step_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_social_step_run` (`run_id`,`step_no`),
  ADD KEY `idx_social_step_run_state` (`run_id`,`state`);

--
-- فهارس للجدول `social_publications`
--
ALTER TABLE `social_publications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_publication_content` (`content_id`),
  ADD KEY `idx_publication_account` (`account_id`),
  ADD KEY `idx_publication_state` (`platform`,`state`),
  ADD KEY `idx_publication_job` (`job_id`),
  ADD KEY `idx_publication_reconcile` (`state`,`platform`,`last_checked_at`);

--
-- فهارس للجدول `system_archives`
--
ALTER TABLE `system_archives`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_archive_source` (`data_key`,`source_table`,`source_id`),
  ADD KEY `idx_system_archives_date` (`data_key`,`archived_at`);

--
-- فهارس للجدول `system_errors`
--
ALTER TABLE `system_errors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_errors_ref` (`error_ref`),
  ADD KEY `idx_system_errors_created` (`created_at`);

--
-- فهارس للجدول `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uid` (`uid`),
  ADD KEY `parent_task_id` (`parent_task_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `created_by_agent_id` (`created_by_agent_id`),
  ADD KEY `assigned_agent_id` (`assigned_agent_id`);

--
-- فهارس للجدول `task_dependencies`
--
ALTER TABLE `task_dependencies`
  ADD PRIMARY KEY (`task_id`,`depends_on_task_id`),
  ADD KEY `depends_on_task_id` (`depends_on_task_id`);

--
-- فهارس للجدول `task_events`
--
ALTER TABLE `task_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`);

--
-- فهارس للجدول `task_evidence`
--
ALTER TABLE `task_evidence`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_task_evidence_task` (`task_id`,`state`),
  ADD KEY `idx_task_evidence_project` (`project_id`,`created_at`);

--
-- فهارس للجدول `team_chat_messages`
--
ALTER TABLE `team_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_team_chat_created` (`created_at`),
  ADD KEY `idx_team_chat_project` (`project_id`,`id`),
  ADD KEY `idx_team_chat_task` (`task_id`,`id`),
  ADD KEY `idx_team_chat_receiver` (`receiver_ref`,`id`);

--
-- فهارس للجدول `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- فهارس للجدول `video_audio_assets`
--
ALTER TABLE `video_audio_assets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_video_audio_prod` (`production_id`,`scene_id`,`state`);

--
-- فهارس للجدول `video_continuity_assets`
--
ALTER TABLE `video_continuity_assets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_video_continuity_prod` (`production_id`,`asset_type`);

--
-- فهارس للجدول `video_productions`
--
ALTER TABLE `video_productions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_video_agent` (`created_by_agent_id`),
  ADD KEY `idx_video_state` (`state`),
  ADD KEY `idx_video_project` (`project_id`);

--
-- فهارس للجدول `video_render_jobs`
--
ALTER TABLE `video_render_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_video_render_prod` (`production_id`,`state`),
  ADD KEY `idx_video_render_media_request` (`media_request_id`);

--
-- فهارس للجدول `video_scenes`
--
ALTER TABLE `video_scenes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_video_scene` (`production_id`,`scene_no`),
  ADD KEY `idx_video_scene_state` (`production_id`,`state`);

--
-- فهارس للجدول `voice_conversations`
--
ALTER TABLE `voice_conversations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_voice_conversation_uid` (`uid`),
  ADD UNIQUE KEY `uq_voice_conversation_uid17` (`uid`),
  ADD KEY `idx_voice_agent_state` (`agent_id`,`state`,`updated_at`),
  ADD KEY `idx_voice_external` (`external_call_id`);

--
-- فهارس للجدول `voice_turns`
--
ALTER TABLE `voice_turns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_voice_turn_no` (`conversation_id`,`turn_no`),
  ADD KEY `idx_voice_turn_created` (`conversation_id`,`created_at`);

--
-- فهارس للجدول `webhook_events`
--
ALTER TABLE `webhook_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_provider_event` (`provider`,`external_event_id`);

--
-- فهارس للجدول `whatsapp_call_sessions`
--
ALTER TABLE `whatsapp_call_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_whatsapp_call_provider` (`provider_call_id`),
  ADD KEY `idx_whatsapp_calls_state` (`state`,`created_at`);

--
-- فهارس للجدول `whatsapp_pending_messages`
--
ALTER TABLE `whatsapp_pending_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wap_customer_state` (`customer_id`,`state`,`id`),
  ADD KEY `idx_wap_template_message` (`template_message_id`),
  ADD KEY `idx_wap_conversation` (`conversation_id`);

--
-- فهارس للجدول `worker_heartbeat_log`
--
ALTER TABLE `worker_heartbeat_log`
  ADD PRIMARY KEY (`heartbeat_minute`),
  ADD KEY `idx_worker_heartbeat_seen` (`last_seen_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `agency_creators`
--
ALTER TABLE `agency_creators`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agency_creator_monthly`
--
ALTER TABLE `agency_creator_monthly`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agency_events`
--
ALTER TABLE `agency_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agency_imports`
--
ALTER TABLE `agency_imports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agency_threads`
--
ALTER TABLE `agency_threads`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agency_thread_messages`
--
ALTER TABLE `agency_thread_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agents`
--
ALTER TABLE `agents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_budget_reservations`
--
ALTER TABLE `agent_budget_reservations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_capability_route_preferences`
--
ALTER TABLE `agent_capability_route_preferences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_change_proposals`
--
ALTER TABLE `agent_change_proposals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_change_rollbacks`
--
ALTER TABLE `agent_change_rollbacks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_data_fields`
--
ALTER TABLE `agent_data_fields`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_data_rows`
--
ALTER TABLE `agent_data_rows`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_data_schemas`
--
ALTER TABLE `agent_data_schemas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_external_actions`
--
ALTER TABLE `agent_external_actions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_followups`
--
ALTER TABLE `agent_followups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_goal_contributions`
--
ALTER TABLE `agent_goal_contributions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_initiatives`
--
ALTER TABLE `agent_initiatives`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_kpi_daily`
--
ALTER TABLE `agent_kpi_daily`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_kpi_definitions`
--
ALTER TABLE `agent_kpi_definitions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_learning`
--
ALTER TABLE `agent_learning`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_memory`
--
ALTER TABLE `agent_memory`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_memory_bank`
--
ALTER TABLE `agent_memory_bank`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_memory_links`
--
ALTER TABLE `agent_memory_links`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_performance_snapshots`
--
ALTER TABLE `agent_performance_snapshots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_policy_decisions`
--
ALTER TABLE `agent_policy_decisions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_policy_rules`
--
ALTER TABLE `agent_policy_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_prompt_versions`
--
ALTER TABLE `agent_prompt_versions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_runs`
--
ALTER TABLE `agent_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_schema_requests`
--
ALTER TABLE `agent_schema_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_usage_events`
--
ALTER TABLE `agent_usage_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_usage_ledger`
--
ALTER TABLE `agent_usage_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_workflow_runs`
--
ALTER TABLE `agent_workflow_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_workflow_run_steps`
--
ALTER TABLE `agent_workflow_run_steps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_workflow_steps`
--
ALTER TABLE `agent_workflow_steps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_workflow_templates`
--
ALTER TABLE `agent_workflow_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `backups`
--
ALTER TABLE `backups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calls`
--
ALTER TABLE `calls`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `change_requests`
--
ALTER TABLE `change_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cloud_objects`
--
ALTER TABLE `cloud_objects`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `communication_channels`
--
ALTER TABLE `communication_channels`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `communication_events`
--
ALTER TABLE `communication_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_goals`
--
ALTER TABLE `company_goals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_goal_events`
--
ALTER TABLE `company_goal_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_memory`
--
ALTER TABLE `company_memory`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `connection_tests`
--
ALTER TABLE `connection_tests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_directory`
--
ALTER TABLE `contact_directory`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conversation_sessions`
--
ALTER TABLE `conversation_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `creator_intelligence_snapshots`
--
ALTER TABLE `creator_intelligence_snapshots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_memory`
--
ALTER TABLE `customer_memory`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deployments`
--
ALTER TABLE `deployments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `free_model_catalog`
--
ALTER TABLE `free_model_catalog`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `free_model_scout_runs`
--
ALTER TABLE `free_model_scout_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_environments`
--
ALTER TABLE `lab_environments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `media_requests`
--
ALTER TABLE `media_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migration_preflight_runs`
--
ALTER TABLE `migration_preflight_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `opportunities`
--
ALTER TABLE `opportunities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `opportunity_cost_observations`
--
ALTER TABLE `opportunity_cost_observations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `opportunity_raw_items`
--
ALTER TABLE `opportunity_raw_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `opportunity_search_runs`
--
ALTER TABLE `opportunity_search_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `opportunity_sources`
--
ALTER TABLE `opportunity_sources`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `owner_decision_requests`
--
ALTER TABLE `owner_decision_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pending_actions`
--
ALTER TABLE `pending_actions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `physical_schema_requests`
--
ALTER TABLE `physical_schema_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_assets`
--
ALTER TABLE `project_assets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_briefs`
--
ALTER TABLE `project_briefs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_chat_messages`
--
ALTER TABLE `project_chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_cost_entries`
--
ALTER TABLE `project_cost_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_databases`
--
ALTER TABLE `project_databases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_domains`
--
ALTER TABLE `project_domains`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_files`
--
ALTER TABLE `project_files`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_issues`
--
ALTER TABLE `project_issues`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_memory`
--
ALTER TABLE `project_memory`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_scans`
--
ALTER TABLE `project_scans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_stage_events`
--
ALTER TABLE `project_stage_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prospecting_runs`
--
ALTER TABLE `prospecting_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prospect_contact_evidence`
--
ALTER TABLE `prospect_contact_evidence`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prospect_leads`
--
ALTER TABLE `prospect_leads`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prospect_prototypes`
--
ALTER TABLE `prospect_prototypes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prospect_website_audits`
--
ALTER TABLE `prospect_website_audits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `provider_pricing`
--
ALTER TABLE `provider_pricing`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotes`
--
ALTER TABLE `quotes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ramy_repair_runs`
--
ALTER TABLE `ramy_repair_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `release_history`
--
ALTER TABLE `release_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resource_registry`
--
ALTER TABLE `resource_registry`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resource_registry_events`
--
ALTER TABLE `resource_registry_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resource_requests`
--
ALTER TABLE `resource_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `review_tests`
--
ALTER TABLE `review_tests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `runtime_validation_runs`
--
ALTER TABLE `runtime_validation_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `runtime_validation_steps`
--
ALTER TABLE `runtime_validation_steps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schema_migration_steps`
--
ALTER TABLE `schema_migration_steps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `secure_config_snapshots`
--
ALTER TABLE `secure_config_snapshots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `secure_vault_snapshots`
--
ALTER TABLE `secure_vault_snapshots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_authorizations`
--
ALTER TABLE `security_authorizations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_findings`
--
ALTER TABLE `security_findings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_retests`
--
ALTER TABLE `security_retests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_targets`
--
ALTER TABLE `security_targets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_test_cases`
--
ALTER TABLE `security_test_cases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_test_events`
--
ALTER TABLE `security_test_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_test_runs`
--
ALTER TABLE `security_test_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_accounts`
--
ALTER TABLE `service_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_accounts`
--
ALTER TABLE `social_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_account_runs`
--
ALTER TABLE `social_account_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_contacts`
--
ALTER TABLE `social_contacts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_content`
--
ALTER TABLE `social_content`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_interactions`
--
ALTER TABLE `social_interactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_metrics`
--
ALTER TABLE `social_metrics`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_playbooks`
--
ALTER TABLE `social_playbooks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_playbook_steps`
--
ALTER TABLE `social_playbook_steps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_playbook_step_runs`
--
ALTER TABLE `social_playbook_step_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_publications`
--
ALTER TABLE `social_publications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_archives`
--
ALTER TABLE `system_archives`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_errors`
--
ALTER TABLE `system_errors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_events`
--
ALTER TABLE `task_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_evidence`
--
ALTER TABLE `task_evidence`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team_chat_messages`
--
ALTER TABLE `team_chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_audio_assets`
--
ALTER TABLE `video_audio_assets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_continuity_assets`
--
ALTER TABLE `video_continuity_assets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_productions`
--
ALTER TABLE `video_productions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_render_jobs`
--
ALTER TABLE `video_render_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_scenes`
--
ALTER TABLE `video_scenes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `voice_conversations`
--
ALTER TABLE `voice_conversations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `voice_turns`
--
ALTER TABLE `voice_turns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `webhook_events`
--
ALTER TABLE `webhook_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `whatsapp_call_sessions`
--
ALTER TABLE `whatsapp_call_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `whatsapp_pending_messages`
--
ALTER TABLE `whatsapp_pending_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- القيود المفروضة على الجداول الملقاة
--

--
-- قيود الجداول `agents`
--
ALTER TABLE `agents`
  ADD CONSTRAINT `fk_agents_manager` FOREIGN KEY (`manager_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `agent_channel_permissions`
--
ALTER TABLE `agent_channel_permissions`
  ADD CONSTRAINT `agent_channel_permissions_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `agent_data_fields`
--
ALTER TABLE `agent_data_fields`
  ADD CONSTRAINT `fk_agent_data_field_schema` FOREIGN KEY (`schema_id`) REFERENCES `agent_data_schemas` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `agent_data_rows`
--
ALTER TABLE `agent_data_rows`
  ADD CONSTRAINT `fk_agent_data_row_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_agent_data_row_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_agent_data_row_schema` FOREIGN KEY (`schema_id`) REFERENCES `agent_data_schemas` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `agent_data_schemas`
--
ALTER TABLE `agent_data_schemas`
  ADD CONSTRAINT `fk_agent_data_schema_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `agent_memory`
--
ALTER TABLE `agent_memory`
  ADD CONSTRAINT `agent_memory_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_agent_memory_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `agent_permissions`
--
ALTER TABLE `agent_permissions`
  ADD CONSTRAINT `agent_permissions_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `agent_permissions_ibfk_2` FOREIGN KEY (`permission_key`) REFERENCES `permissions` (`permission_key`) ON DELETE CASCADE;

--
-- قيود الجداول `agent_project_access`
--
ALTER TABLE `agent_project_access`
  ADD CONSTRAINT `fk_agent_project_access_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_agent_project_access_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `agent_relationships`
--
ALTER TABLE `agent_relationships`
  ADD CONSTRAINT `agent_relationships_ibfk_1` FOREIGN KEY (`from_agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `agent_relationships_ibfk_2` FOREIGN KEY (`to_agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `agent_runs`
--
ALTER TABLE `agent_runs`
  ADD CONSTRAINT `agent_runs_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_agent_runs_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `agent_tools`
--
ALTER TABLE `agent_tools`
  ADD CONSTRAINT `agent_tools_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `audit_logs_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `backups`
--
ALTER TABLE `backups`
  ADD CONSTRAINT `backups_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `backups_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `calls`
--
ALTER TABLE `calls`
  ADD CONSTRAINT `calls_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `calls_ibfk_2` FOREIGN KEY (`channel_id`) REFERENCES `communication_channels` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `calls_ibfk_3` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `calls_ibfk_4` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `change_requests`
--
ALTER TABLE `change_requests`
  ADD CONSTRAINT `change_requests_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `change_requests_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_change_request_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `communication_events`
--
ALTER TABLE `communication_events`
  ADD CONSTRAINT `communication_events_ibfk_1` FOREIGN KEY (`channel_id`) REFERENCES `communication_channels` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `communication_events_ibfk_2` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `conversations`
--
ALTER TABLE `conversations`
  ADD CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `conversation_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conversations_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `conversations_ibfk_3` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `conversations_ibfk_4` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `conversation_sessions`
--
ALTER TABLE `conversation_sessions`
  ADD CONSTRAINT `conversation_sessions_ibfk_1` FOREIGN KEY (`active_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `conversation_sessions_ibfk_2` FOREIGN KEY (`active_task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `conversation_sessions_ibfk_3` FOREIGN KEY (`last_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `customer_projects`
--
ALTER TABLE `customer_projects`
  ADD CONSTRAINT `customer_projects_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_projects_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `deployments`
--
ALTER TABLE `deployments`
  ADD CONSTRAINT `deployments_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `deployments_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `jobs`
--
ALTER TABLE `jobs`
  ADD CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `jobs_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `jobs_ibfk_3` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `media_requests`
--
ALTER TABLE `media_requests`
  ADD CONSTRAINT `media_requests_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `media_requests_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `media_requests_ibfk_3` FOREIGN KEY (`requested_by_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `media_requests_ibfk_4` FOREIGN KEY (`result_asset_id`) REFERENCES `project_assets` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `conversation_sessions` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `opportunities`
--
ALTER TABLE `opportunities`
  ADD CONSTRAINT `fk_opportunity_agent` FOREIGN KEY (`discovered_by_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_opportunity_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_opportunity_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_opportunity_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `owner_channel_routes`
--
ALTER TABLE `owner_channel_routes`
  ADD CONSTRAINT `fk_owner_route_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_owner_route_session` FOREIGN KEY (`session_id`) REFERENCES `conversation_sessions` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_ibfk_4` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `pending_actions`
--
ALTER TABLE `pending_actions`
  ADD CONSTRAINT `pending_actions_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `conversation_sessions` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `project_assets`
--
ALTER TABLE `project_assets`
  ADD CONSTRAINT `project_assets_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `project_cost_entries`
--
ALTER TABLE `project_cost_entries`
  ADD CONSTRAINT `fk_project_cost_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_project_cost_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_project_cost_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `project_databases`
--
ALTER TABLE `project_databases`
  ADD CONSTRAINT `project_databases_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `project_domains`
--
ALTER TABLE `project_domains`
  ADD CONSTRAINT `project_domains_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `project_files`
--
ALTER TABLE `project_files`
  ADD CONSTRAINT `project_files_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `project_issues`
--
ALTER TABLE `project_issues`
  ADD CONSTRAINT `project_issues_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `project_issues_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `project_issues_ibfk_3` FOREIGN KEY (`source_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `project_memory`
--
ALTER TABLE `project_memory`
  ADD CONSTRAINT `project_memory_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `project_scans`
--
ALTER TABLE `project_scans`
  ADD CONSTRAINT `project_scans_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `quotes`
--
ALTER TABLE `quotes`
  ADD CONSTRAINT `quotes_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quotes_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`reviewer_agent_id`) REFERENCES `agents` (`id`);

--
-- قيود الجداول `review_tests`
--
ALTER TABLE `review_tests`
  ADD CONSTRAINT `review_tests_ibfk_1` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`parent_task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tasks_ibfk_3` FOREIGN KEY (`created_by_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tasks_ibfk_4` FOREIGN KEY (`assigned_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `task_dependencies`
--
ALTER TABLE `task_dependencies`
  ADD CONSTRAINT `task_dependencies_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_dependencies_ibfk_2` FOREIGN KEY (`depends_on_task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `task_events`
--
ALTER TABLE `task_events`
  ADD CONSTRAINT `task_events_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
