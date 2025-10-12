-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : lun. 06 oct. 2025 à 21:54
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `cafe7792_simpliz`
--

-- --------------------------------------------------------

--
-- Structure de la table `agences`
--

CREATE TABLE `agences` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) NOT NULL,
  `nom` varchar(120) NOT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `agences`
--

INSERT INTO `agences` (`id`, `entreprise_id`, `nom`, `adresse`, `actif`, `created_at`) VALUES
(10, 1, 'Montréjeau', NULL, 1, '2025-09-01 20:33:18'),
(11, 1, 'Toulouse', NULL, 1, '2025-09-01 20:33:44');

-- --------------------------------------------------------

--
-- Structure de la table `article_etats`
--

CREATE TABLE `article_etats` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) NOT NULL,
  `article_id` int(11) NOT NULL,
  `profil_qr` enum('aucun','compteur_heures','autre') NOT NULL DEFAULT 'aucun',
  `action` enum('compteur_maj','declarer_ok','declarer_panne') NOT NULL,
  `valeur_int` int(11) DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `fichier` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `article_etats`
--

INSERT INTO `article_etats` (`id`, `entreprise_id`, `article_id`, `profil_qr`, `action`, `valeur_int`, `commentaire`, `fichier`, `created_by`, `created_at`) VALUES
(30, 1, 25, 'autre', 'declarer_panne', NULL, 'fuites', NULL, 4, '2025-10-06 21:53:14');

-- --------------------------------------------------------

--
-- Structure de la table `chantiers`
--

CREATE TABLE `chantiers` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `nom` varchar(255) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `trajet_distance_m` int(11) DEFAULT NULL,
  `trajet_duree_s` int(11) DEFAULT NULL,
  `trajet_zone` tinyint(4) DEFAULT NULL,
  `trajet_last_calc` datetime DEFAULT NULL,
  `adresse_lat` decimal(10,7) DEFAULT NULL,
  `adresse_lng` decimal(10,7) DEFAULT NULL,
  `description` text NOT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `etat` enum('en_cours','fini') NOT NULL DEFAULT 'en_cours',
  `responsable_id` int(11) DEFAULT NULL,
  `depot_id` int(11) DEFAULT NULL,
  `agence_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `chantiers`
--

INSERT INTO `chantiers` (`id`, `entreprise_id`, `nom`, `adresse`, `trajet_distance_m`, `trajet_duree_s`, `trajet_zone`, `trajet_last_calc`, `adresse_lat`, `adresse_lng`, `description`, `date_debut`, `date_fin`, `etat`, `responsable_id`, `depot_id`, `agence_id`, `created_at`, `updated_at`) VALUES
(1, 1, 'Bouchait', '57 rue du pic du midi, 65150 saint-paul', 5995, 425, NULL, '2025-09-29 21:53:53', 43.0838391, 0.5017705, '', '2025-09-19', '2025-09-26', 'en_cours', 4, 1, NULL, '2025-07-17 20:27:49', '2025-09-29 21:53:53'),
(36, 1, 'Piscine Auterive', 'Place du 11 novembre 1918 31190, Auterive', 90873, 3992, NULL, '2025-09-29 21:53:54', 43.3524333, 1.4779796, '', NULL, NULL, 'en_cours', 17, 1, NULL, '2025-09-29 19:00:00', '2025-09-29 21:53:54');

-- --------------------------------------------------------

--
-- Structure de la table `chantier_taches`
--

CREATE TABLE `chantier_taches` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) NOT NULL,
  `chantier_id` int(11) NOT NULL,
  `nom` varchar(190) NOT NULL,
  `shortcut` varchar(100) DEFAULT NULL,
  `unite` varchar(20) DEFAULT NULL,
  `quantite` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tu_heures` decimal(9,2) NOT NULL DEFAULT 0.00,
  `tu_minutes` decimal(9,2) NOT NULL DEFAULT 0.00,
  `avancement_pct` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `chantier_taches`
--

INSERT INTO `chantier_taches` (`id`, `entreprise_id`, `chantier_id`, `nom`, `shortcut`, `unite`, `quantite`, `tu_heures`, `tu_minutes`, `avancement_pct`, `created_at`, `updated_at`) VALUES
(12, 1, 1, 'depose menuiseries exterieures', 'demol menuiseries ext', 'u', 1.00, 78.00, 0.00, 0, '2025-09-21 14:25:32', '2025-09-26 00:00:00'),
(13, 1, 1, 'Installation de chantier', 'Installation chantier', 'u', 1.00, 123.30, 0.00, 0, '2025-09-21 14:29:40', '2025-09-26 00:00:00'),
(14, 1, 1, 'depose ouvrage de structure', 'Demo dallage', 'm2', 1207.50, 0.34, 0.00, 10, '2025-09-21 16:59:56', '2025-09-26 00:00:00');

-- --------------------------------------------------------

--
-- Structure de la table `depots`
--

CREATE TABLE `depots` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `nom` varchar(255) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `agence_id` int(11) DEFAULT NULL,
  `adresse_lat` decimal(10,7) DEFAULT NULL,
  `adresse_lng` decimal(10,7) DEFAULT NULL,
  `responsable_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `depots`
--

INSERT INTO `depots` (`id`, `entreprise_id`, `nom`, `adresse`, `agence_id`, `adresse_lat`, `adresse_lng`, `responsable_id`, `created_at`) VALUES
(1, 1, 'Montréjeau', 'avenue des toureilles, 31210 montrejeau', 10, 43.0929121, 0.5603920, 6, '2025-07-17 20:31:20'),
(2, 1, 'Toulouse', 'place du capitole, 31000 toulouse', 11, 43.6044071, 1.4433773, 8, '2025-08-19 19:38:14');

-- --------------------------------------------------------

--
-- Structure de la table `entreprises`
--

CREATE TABLE `entreprises` (
  `id` int(10) UNSIGNED NOT NULL,
  `nom` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `entreprises`
--

INSERT INTO `entreprises` (`id`, `nom`, `created_at`) VALUES
(1, 'Mon Entreprise', '2025-09-01 17:20:19');

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `stock_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `lu` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `planning_affectations`
--

CREATE TABLE `planning_affectations` (
  `id` int(10) UNSIGNED NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `chantier_id` int(11) DEFAULT NULL,
  `depot_id` int(11) DEFAULT NULL,
  `date_jour` date NOT NULL,
  `type` enum('chantier','depot','conges','maladie','rtt') NOT NULL DEFAULT 'chantier',
  `role` enum('chef','employe') NOT NULL DEFAULT 'employe',
  `heures` decimal(5,2) DEFAULT 0.00,
  `commentaire` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `planning_affectations`
--

INSERT INTO `planning_affectations` (`id`, `entreprise_id`, `utilisateur_id`, `chantier_id`, `depot_id`, `date_jour`, `type`, `role`, `heures`, `commentaire`, `created_at`, `is_active`) VALUES
(46, 1, 16, 1, NULL, '2025-09-01', 'chantier', 'employe', 0.00, NULL, '2025-09-04 17:26:30', 0),
(47, 1, 16, 1, NULL, '2025-09-02', 'chantier', 'employe', 0.00, NULL, '2025-09-04 17:26:30', 0),
(48, 1, 16, 1, NULL, '2025-09-03', 'chantier', 'employe', 0.00, NULL, '2025-09-04 17:26:31', 0),
(53, 1, 16, 1, NULL, '2025-09-04', 'chantier', 'employe', 0.00, NULL, '2025-09-04 17:27:02', 0),
(111, 1, 16, 1, NULL, '2025-09-05', 'chantier', 'employe', 0.00, NULL, '2025-09-05 19:41:24', 0),
(131, 1, 4, 1, NULL, '2025-09-01', 'chantier', 'employe', 0.00, NULL, '2025-09-05 20:28:46', 0),
(294, 1, 4, 1, NULL, '2025-09-08', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:19', 0),
(296, 1, 4, 1, NULL, '2025-09-10', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:19', 0),
(297, 1, 4, 1, NULL, '2025-09-11', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:19', 0),
(298, 1, 4, 1, NULL, '2025-09-12', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:19', 1),
(394, 1, 4, 1, NULL, '2025-09-15', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:42', 1),
(395, 1, 4, 1, NULL, '2025-09-16', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:43', 0),
(396, 1, 4, 1, NULL, '2025-09-17', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:43', 0),
(397, 1, 4, 1, NULL, '2025-09-18', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:43', 0),
(398, 1, 4, 1, NULL, '2025-09-19', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:43', 1),
(451, 1, 4, 1, NULL, '2025-09-09', 'chantier', 'employe', 0.00, NULL, '2025-09-14 14:24:07', 0),
(511, 1, 16, 1, NULL, '2025-09-15', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:32', 1),
(512, 1, 16, 1, NULL, '2025-09-16', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:32', 1),
(513, 1, 16, 1, NULL, '2025-09-17', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:32', 1),
(514, 1, 16, 1, NULL, '2025-09-18', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:33', 1),
(515, 1, 16, 1, NULL, '2025-09-19', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:33', 1),
(516, 1, 6, NULL, NULL, '2025-09-15', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:36', 1),
(517, 1, 6, NULL, NULL, '2025-09-16', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:36', 1),
(518, 1, 6, NULL, NULL, '2025-09-17', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:36', 1),
(519, 1, 6, NULL, NULL, '2025-09-18', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:36', 1),
(520, 1, 6, NULL, NULL, '2025-09-19', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:37', 1),
(521, 1, 8, NULL, NULL, '2025-09-19', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:37', 1),
(522, 1, 8, NULL, NULL, '2025-09-18', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:37', 1),
(523, 1, 8, NULL, NULL, '2025-09-17', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:37', 1),
(524, 1, 8, NULL, NULL, '2025-09-16', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:38', 1),
(525, 1, 8, NULL, NULL, '2025-09-15', 'depot', 'employe', 0.00, NULL, '2025-09-16 19:27:38', 1),
(556, 1, 16, 1, NULL, '2025-09-23', 'chantier', 'employe', 0.00, NULL, '2025-09-21 14:58:15', 1),
(557, 1, 16, 1, NULL, '2025-09-24', 'chantier', 'employe', 0.00, NULL, '2025-09-21 14:58:15', 1),
(559, 1, 4, 1, NULL, '2025-09-25', 'chantier', 'employe', 0.00, NULL, '2025-09-21 14:58:16', 1),
(560, 1, 4, 1, NULL, '2025-09-24', 'chantier', 'employe', 0.00, NULL, '2025-09-21 14:58:16', 1),
(561, 1, 4, 1, NULL, '2025-09-23', 'chantier', 'employe', 0.00, NULL, '2025-09-21 14:58:17', 1),
(562, 1, 4, 1, NULL, '2025-09-22', 'chantier', 'employe', 0.00, NULL, '2025-09-21 14:58:17', 1),
(573, 1, 8, NULL, NULL, '2025-09-25', 'depot', 'employe', 0.00, NULL, '2025-09-24 19:25:06', 1),
(574, 1, 8, NULL, NULL, '2025-09-24', 'depot', 'employe', 0.00, NULL, '2025-09-24 19:25:06', 1),
(575, 1, 8, NULL, NULL, '2025-09-23', 'depot', 'employe', 0.00, NULL, '2025-09-24 19:25:07', 1),
(645, 1, 16, 1, NULL, '2025-09-25', 'chantier', 'employe', 0.00, NULL, '2025-09-25 16:35:16', 1),
(664, 1, 8, NULL, NULL, '2025-09-22', 'depot', 'employe', 0.00, NULL, '2025-09-25 16:50:17', 1),
(673, 1, 16, 1, NULL, '2025-09-22', 'chantier', 'employe', 0.00, NULL, '2025-09-25 16:51:39', 1),
(708, 1, 6, NULL, NULL, '2025-09-25', 'depot', 'employe', 0.00, NULL, '2025-09-25 17:16:35', 1),
(709, 1, 6, NULL, NULL, '2025-09-24', 'depot', 'employe', 0.00, NULL, '2025-09-25 17:16:35', 1),
(710, 1, 6, NULL, NULL, '2025-09-23', 'depot', 'employe', 0.00, NULL, '2025-09-25 17:16:36', 1),
(711, 1, 6, NULL, NULL, '2025-09-22', 'depot', 'employe', 0.00, NULL, '2025-09-25 17:16:37', 1),
(765, 1, 16, 1, NULL, '2025-09-26', 'chantier', 'employe', 0.00, NULL, '2025-09-26 16:50:12', 1),
(769, 1, 4, 1, NULL, '2025-09-26', 'chantier', 'employe', 0.00, NULL, '2025-09-26 16:50:42', 1),
(782, 1, 6, NULL, NULL, '2025-09-26', 'depot', 'employe', 0.00, NULL, '2025-09-26 19:33:25', 1),
(783, 1, 8, NULL, NULL, '2025-09-26', 'depot', 'employe', 0.00, NULL, '2025-09-26 19:33:25', 1),
(802, 1, 4, 1, NULL, '2025-09-27', 'chantier', 'employe', 0.00, NULL, '2025-09-27 19:24:26', 1),
(804, 1, 4, 1, NULL, '2025-09-28', 'chantier', 'employe', 0.00, NULL, '2025-09-27 19:24:27', 1),
(814, 1, 16, 1, NULL, '2025-09-27', 'chantier', 'employe', 0.00, NULL, '2025-09-28 16:07:35', 1),
(821, 1, 16, 1, NULL, '2025-09-28', 'chantier', 'employe', 0.00, NULL, '2025-09-28 16:33:26', 1),
(825, 1, 8, NULL, NULL, '2025-09-28', 'depot', 'employe', 0.00, NULL, '2025-09-28 19:27:01', 1),
(826, 1, 6, NULL, NULL, '2025-09-28', 'depot', 'employe', 0.00, NULL, '2025-09-28 19:27:03', 1),
(827, 1, 6, NULL, NULL, '2025-09-27', 'depot', 'employe', 0.00, NULL, '2025-09-28 19:27:04', 1),
(836, 1, 8, NULL, NULL, '2025-09-27', 'depot', 'employe', 0.00, NULL, '2025-09-28 19:27:07', 1),
(837, 1, 6, NULL, NULL, '2025-09-29', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:18', 1),
(838, 1, 6, NULL, NULL, '2025-09-30', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:18', 1),
(839, 1, 6, NULL, NULL, '2025-10-01', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:18', 1),
(840, 1, 6, NULL, NULL, '2025-10-02', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:19', 1),
(841, 1, 6, NULL, NULL, '2025-10-03', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:19', 1),
(842, 1, 8, NULL, NULL, '2025-10-03', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:20', 1),
(843, 1, 8, NULL, NULL, '2025-10-02', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:20', 1),
(844, 1, 8, NULL, NULL, '2025-10-01', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:20', 1),
(845, 1, 8, NULL, NULL, '2025-09-30', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:20', 1),
(846, 1, 8, NULL, NULL, '2025-09-29', 'depot', 'employe', 0.00, NULL, '2025-09-29 18:13:20', 1),
(847, 1, 16, 1, NULL, '2025-09-29', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:27', 1),
(848, 1, 16, 1, NULL, '2025-09-30', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:27', 1),
(849, 1, 16, 1, NULL, '2025-10-01', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:28', 1),
(850, 1, 16, 1, NULL, '2025-10-02', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:28', 1),
(851, 1, 16, 1, NULL, '2025-10-03', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:28', 1),
(852, 1, 4, 1, NULL, '2025-09-29', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:34', 1),
(853, 1, 4, 1, NULL, '2025-09-30', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:34', 1),
(854, 1, 4, 1, NULL, '2025-10-01', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:34', 1),
(855, 1, 4, 1, NULL, '2025-10-02', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:34', 1),
(856, 1, 4, 1, NULL, '2025-10-03', 'chantier', 'employe', 0.00, NULL, '2025-09-29 18:13:35', 1),
(863, 1, 17, 36, NULL, '2025-09-29', 'chantier', 'employe', 0.00, NULL, '2025-09-29 19:00:20', 1),
(864, 1, 17, 36, NULL, '2025-09-30', 'chantier', 'employe', 0.00, NULL, '2025-09-29 19:00:21', 1),
(865, 1, 17, 36, NULL, '2025-10-01', 'chantier', 'employe', 0.00, NULL, '2025-09-29 19:00:21', 1),
(866, 1, 17, 36, NULL, '2025-10-02', 'chantier', 'employe', 0.00, NULL, '2025-09-29 19:00:21', 1),
(867, 1, 17, 36, NULL, '2025-10-03', 'chantier', 'employe', 0.00, NULL, '2025-09-29 19:00:21', 1);

-- --------------------------------------------------------

--
-- Structure de la table `pointages_absences`
--

CREATE TABLE `pointages_absences` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `date_jour` date NOT NULL,
  `motif` enum('conges_payes','conges_intemperies','maladie','justifie','injustifie','rtt') NOT NULL,
  `heures` decimal(5,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `pointages_camions`
--

CREATE TABLE `pointages_camions` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) NOT NULL,
  `chantier_id` int(11) NOT NULL,
  `date_jour` date NOT NULL,
  `nb_camions` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `pointages_camions`
--

INSERT INTO `pointages_camions` (`id`, `entreprise_id`, `chantier_id`, `date_jour`, `nb_camions`) VALUES
(1, 1, 1, '2025-09-09', 1),
(34, 1, 2, '2025-09-09', 1),
(38, 1, 1, '2025-09-10', 1),
(40, 1, 1, '2025-09-08', 1),
(42, 1, 2, '2025-09-08', 1);

-- --------------------------------------------------------

--
-- Structure de la table `pointages_conduite`
--

CREATE TABLE `pointages_conduite` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `chantier_id` int(11) NOT NULL,
  `date_pointage` date NOT NULL,
  `type` enum('A','R') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `pointages_conduite`
--

INSERT INTO `pointages_conduite` (`id`, `entreprise_id`, `utilisateur_id`, `chantier_id`, `date_pointage`, `type`, `created_at`, `updated_at`) VALUES
(41, 1, 16, 1, '2025-09-29', 'A', '2025-09-29 20:56:14', '2025-09-29 20:56:14'),
(42, 1, 4, 1, '2025-09-29', 'R', '2025-09-29 20:56:14', '2025-09-29 20:56:14'),
(43, 1, 17, 35, '2025-09-29', 'A', '2025-09-29 20:56:28', '2025-09-29 20:56:28'),
(44, 1, 17, 35, '2025-09-29', 'R', '2025-09-29 20:56:29', '2025-09-29 20:56:29');

-- --------------------------------------------------------

--
-- Structure de la table `pointages_jour`
--

CREATE TABLE `pointages_jour` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `date_jour` date NOT NULL,
  `chantier_id` int(11) DEFAULT NULL,
  `tache_id` int(11) DEFAULT NULL,
  `heures` decimal(5,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `pointages_jour`
--

INSERT INTO `pointages_jour` (`id`, `entreprise_id`, `utilisateur_id`, `date_jour`, `chantier_id`, `tache_id`, `heures`, `updated_at`) VALUES
(300, 1, 16, '2025-09-29', 1, 14, 8.25, '2025-09-29 20:56:17'),
(301, 1, 4, '2025-09-29', 1, 14, 8.25, '2025-09-29 20:56:19'),
(304, 1, 17, '2025-09-29', 35, NULL, 8.25, '2025-09-29 20:56:23'),
(305, 1, 6, '2025-09-29', NULL, NULL, 8.25, '2025-09-29 20:56:36'),
(306, 1, 8, '2025-09-29', NULL, NULL, 8.25, '2025-09-29 20:56:40');

-- --------------------------------------------------------

--
-- Structure de la table `pointages_taches`
--

CREATE TABLE `pointages_taches` (
  `id` int(10) UNSIGNED NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `utilisateur_id` int(10) UNSIGNED NOT NULL,
  `chantier_id` int(10) UNSIGNED NOT NULL,
  `date_jour` date NOT NULL,
  `tache_id` int(10) UNSIGNED NOT NULL,
  `heures` decimal(4,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `pointages_taches`
--

INSERT INTO `pointages_taches` (`id`, `entreprise_id`, `utilisateur_id`, `chantier_id`, `date_jour`, `tache_id`, `heures`, `created_at`, `updated_at`) VALUES
(65, 1, 16, 1, '2025-09-29', 14, 8.25, '2025-09-29 18:56:17', '2025-09-29 18:56:17'),
(66, 1, 4, 1, '2025-09-29', 14, 8.25, '2025-09-29 18:56:19', '2025-09-29 18:56:19');

-- --------------------------------------------------------

--
-- Structure de la table `pointage_camions_cfg`
--

CREATE TABLE `pointage_camions_cfg` (
  `entreprise_id` int(11) NOT NULL,
  `chantier_id` int(11) NOT NULL,
  `nb_camions` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `pointage_camions_cfg`
--

INSERT INTO `pointage_camions_cfg` (`entreprise_id`, `chantier_id`, `nb_camions`) VALUES
(1, 1, 1),
(1, 2, 1);

-- --------------------------------------------------------

--
-- Structure de la table `stock`
--

CREATE TABLE `stock` (
  `id` int(11) NOT NULL,
  `qr_token` char(36) DEFAULT NULL,
  `has_qrcode` tinyint(1) NOT NULL DEFAULT 0,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `nom` varchar(255) NOT NULL,
  `quantite_totale` int(11) DEFAULT 0,
  `quantite_disponible` int(11) DEFAULT 0,
  `categorie` varchar(255) DEFAULT NULL,
  `sous_categorie` varchar(255) DEFAULT NULL,
  `dimensions` varchar(100) DEFAULT NULL,
  `poids` varchar(50) DEFAULT NULL,
  `has_hour_meter` tinyint(1) NOT NULL DEFAULT 0,
  `hour_meter_initial` int(10) UNSIGNED DEFAULT NULL,
  `maintenance_threshold` int(10) UNSIGNED DEFAULT NULL,
  `hour_meter_unit` enum('h') NOT NULL DEFAULT 'h',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `depot_id` int(11) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `document` varchar(255) DEFAULT NULL,
  `gestion_mode` enum('anonyme','nominatif') NOT NULL DEFAULT 'anonyme',
  `maintenance_mode` enum('none','hour_meter','electrical') NOT NULL DEFAULT 'none',
  `profil_qr` enum('aucun','compteur_heures','autre') NOT NULL DEFAULT 'aucun',
  `qr_image_path` varchar(255) DEFAULT NULL,
  `compteur_heures` int(11) DEFAULT NULL,
  `panne` tinyint(1) NOT NULL DEFAULT 0,
  `panne_message` text DEFAULT NULL,
  `panne_reported_at` datetime DEFAULT NULL,
  `panne_reported_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock`
--

INSERT INTO `stock` (`id`, `qr_token`, `has_qrcode`, `entreprise_id`, `nom`, `quantite_totale`, `quantite_disponible`, `categorie`, `sous_categorie`, `dimensions`, `poids`, `has_hour_meter`, `hour_meter_initial`, `maintenance_threshold`, `hour_meter_unit`, `created_at`, `depot_id`, `photo`, `document`, `gestion_mode`, `maintenance_mode`, `profil_qr`, `qr_image_path`, `compteur_heures`, `panne`, `panne_message`, `panne_reported_at`, `panne_reported_by`) VALUES
(2, '23755665aeb88483dad50b4cb8f06ccd', 1, 1, 'banche 2m75', 24, 24, 'Banches', 'Manuportables', NULL, NULL, 0, NULL, NULL, 'h', '2025-07-17 20:31:41', NULL, 'uploads/photos/articles/2/68acb990e8a8e5.25520685.jpg', NULL, 'anonyme', 'none', 'aucun', '/stock/qrcodes/1/stock_2.png', NULL, 0, NULL, NULL, NULL),
(10, 'cd15a6380c640100987dba4bd52433bd', 1, 1, 'Piqueur', 23, 23, 'Electroportatif', 'Piquage', NULL, NULL, 0, NULL, NULL, 'h', '2025-07-27 21:08:02', NULL, 'uploads/photos/articles/10/68a6d2139ffb16.19401476.jpg', NULL, 'anonyme', 'none', 'aucun', NULL, NULL, 0, NULL, NULL, NULL),
(11, '08414b741ac5a29f1f5a80f548e499f9', 1, 1, 'Banche 2m40', 20, 20, 'Banches', 'Metalique', NULL, NULL, 0, NULL, NULL, 'h', '2025-08-19 08:56:10', NULL, 'uploads/photos/articles/11/68a70b96268571.58204439.jpg', NULL, 'anonyme', 'none', 'aucun', '/stock/qrcodes/1/stock_11.png', NULL, 0, NULL, NULL, NULL),
(12, '606e0a13e326589f79cd377c8a427793', 1, 1, 'Element blanc', 100, 100, 'Peri', NULL, NULL, NULL, 0, NULL, NULL, 'h', '2025-08-21 17:02:15', NULL, 'uploads/photos/articles/12/68a751c0846558.19603577.png', NULL, 'anonyme', 'none', 'aucun', NULL, NULL, 0, NULL, NULL, NULL),
(15, '29c117bcadb11d2cafc5560d86df49d3', 1, 1, 'Plateau 3m', 100, 100, 'Echafaudage', '3m', NULL, NULL, 0, NULL, NULL, 'h', '2025-09-07 10:13:23', NULL, NULL, NULL, 'anonyme', 'none', 'aucun', NULL, NULL, 0, NULL, NULL, NULL),
(25, 'f603ee8b4f8ee10d27e8f179cadc5497', 1, 1, 'Bungalow vestiaire 1', 1, 1, 'Bungalow', 'Vestiaire', NULL, NULL, 0, NULL, NULL, 'h', '2025-10-04 17:01:58', NULL, NULL, NULL, 'nominatif', 'electrical', 'autre', '/stock/qrcodes/1/stock_25.png', NULL, 1, 'fuites', '2025-10-05 14:19:54', 3),
(26, '5adf49f6ecaa44896bb817975727d161', 1, 1, 'Bungalow vestiaire 2', 1, 1, 'Bungalow', 'Vestiaire', NULL, NULL, 0, NULL, NULL, 'h', '2025-10-04 17:01:58', NULL, NULL, NULL, 'nominatif', 'electrical', 'autre', '/stock/qrcodes/1/stock_26.png', NULL, 0, NULL, NULL, NULL),
(32, '6fa06aa3-c15d-430c-ab2c-4cd050d0d3ef', 0, 1, 'Mini-pelle 5t', 1, 1, 'Pelle', '5t', NULL, NULL, 1, 848, NULL, 'h', '2025-10-05 19:44:30', NULL, NULL, NULL, 'nominatif', 'hour_meter', 'compteur_heures', '/stock/qrcodes/1/stock_32.png', 850, 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `stock_alerts`
--

CREATE TABLE `stock_alerts` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) DEFAULT NULL,
  `stock_id` int(11) NOT NULL,
  `type` enum('incident') NOT NULL DEFAULT 'incident',
  `message` text NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock_alerts`
--

INSERT INTO `stock_alerts` (`id`, `entreprise_id`, `stock_id`, `type`, `message`, `url`, `created_at`, `archived_at`, `archived_by`, `is_read`) VALUES
(32, NULL, 25, '', 'fuites', NULL, '2025-10-06 21:53:14', NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Structure de la table `stock_chantiers`
--

CREATE TABLE `stock_chantiers` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `chantier_id` int(11) NOT NULL,
  `stock_id` int(11) NOT NULL,
  `quantite` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock_chantiers`
--

INSERT INTO `stock_chantiers` (`id`, `entreprise_id`, `chantier_id`, `stock_id`, `quantite`, `created_at`) VALUES
(39, 1, 1, 11, 0, '2025-09-07 19:37:55'),
(48, 1, 1, 2, 0, '2025-09-08 19:10:25'),
(49, 1, 1, 15, 0, '2025-09-20 17:35:54'),
(50, 1, 1, 25, 1, '2025-10-05 15:21:16'),
(52, 1, 1, 32, 1, '2025-10-05 19:45:27');

-- --------------------------------------------------------

--
-- Structure de la table `stock_depots`
--

CREATE TABLE `stock_depots` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `depot_id` int(11) NOT NULL,
  `stock_id` int(11) NOT NULL,
  `quantite` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock_depots`
--

INSERT INTO `stock_depots` (`id`, `entreprise_id`, `depot_id`, `stock_id`, `quantite`) VALUES
(2, 1, 1, 2, 24),
(10, 1, 1, 10, 23),
(11, 1, 1, 11, 20),
(12, 1, 2, 11, 0),
(13, 1, 1, 12, 100),
(14, 1, 2, 12, 0),
(17, 1, 2, 2, 0),
(19, 1, 1, 15, 100),
(28, 1, 1, 25, 0),
(29, 1, 1, 26, 1),
(35, 1, 1, 32, 0);

-- --------------------------------------------------------

--
-- Structure de la table `stock_documents`
--

CREATE TABLE `stock_documents` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(11) NOT NULL,
  `nom_affichage` varchar(255) NOT NULL,
  `chemin_fichier` varchar(500) NOT NULL,
  `type_mime` varchar(100) DEFAULT NULL,
  `taille` int(10) UNSIGNED DEFAULT NULL,
  `checksum_sha1` char(40) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `stock_hour_logs`
--

CREATE TABLE `stock_hour_logs` (
  `id` int(11) NOT NULL,
  `stock_id` int(11) NOT NULL,
  `chantier_id` int(11) DEFAULT NULL,
  `utilisateur_id` int(11) DEFAULT NULL,
  `hours` int(10) UNSIGNED NOT NULL,
  `source` enum('admin','depot','chef','qr') NOT NULL DEFAULT 'qr',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock_hour_logs`
--

INSERT INTO `stock_hour_logs` (`id`, `stock_id`, `chantier_id`, `utilisateur_id`, `hours`, `source`, `created_at`) VALUES
(5, 32, NULL, 3, 1000, 'admin', '2025-10-06 19:31:09'),
(6, 32, NULL, 3, 1010, 'admin', '2025-10-06 19:31:19'),
(7, 32, NULL, 3, 1010, 'admin', '2025-10-06 19:31:23'),
(8, 32, NULL, 3, 850, 'admin', '2025-10-06 19:31:49');

-- --------------------------------------------------------

--
-- Structure de la table `stock_mouvements`
--

CREATE TABLE `stock_mouvements` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(11) NOT NULL,
  `type` enum('transfert','ajout','retrait') NOT NULL DEFAULT 'transfert',
  `source_type` enum('depot','chantier') DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `dest_type` enum('depot','chantier') DEFAULT NULL,
  `dest_id` int(11) DEFAULT NULL,
  `quantite` int(11) NOT NULL,
  `statut` enum('valide','refuse','annule') NOT NULL DEFAULT 'valide',
  `commentaire` varchar(255) DEFAULT NULL,
  `demandeur_id` int(11) DEFAULT NULL,
  `utilisateur_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock_mouvements`
--

INSERT INTO `stock_mouvements` (`id`, `entreprise_id`, `stock_id`, `type`, `source_type`, `source_id`, `dest_type`, `dest_id`, `quantite`, `statut`, `commentaire`, `demandeur_id`, `utilisateur_id`, `created_at`) VALUES
(109, 1, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-09-07 21:37:55'),
(110, 1, 11, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-09-07 21:48:25'),
(111, 1, 11, 'transfert', 'depot', 1, 'depot', 2, 1, 'valide', NULL, 3, 3, '2025-09-07 21:49:05'),
(112, 1, 11, 'transfert', 'depot', 2, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-09-07 21:49:20'),
(116, 1, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-09-07 22:04:12'),
(127, 1, 11, 'transfert', 'depot', 1, 'chantier', 1, 3, 'valide', NULL, 3, 3, '2025-09-08 19:47:47'),
(128, 1, 2, 'transfert', 'depot', 1, 'chantier', 1, 10, 'valide', NULL, 3, 3, '2025-09-08 21:10:25'),
(129, 1, 2, 'transfert', 'chantier', 1, 'depot', 1, 10, 'valide', NULL, 3, 3, '2025-09-08 21:10:40'),
(130, 1, 11, 'transfert', 'chantier', 1, 'depot', 1, 4, 'valide', NULL, 3, 3, '2025-09-08 21:10:51'),
(131, 1, 11, 'transfert', 'depot', 1, 'chantier', 1, 5, 'valide', NULL, 3, 4, '2025-09-08 21:14:16'),
(134, 1, 11, 'transfert', 'chantier', 1, 'depot', 1, 5, 'valide', NULL, 4, 6, '2025-09-08 21:29:08'),
(136, 1, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'refuse', NULL, NULL, 4, '2025-09-08 21:32:05'),
(137, 1, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'refuse', NULL, NULL, 4, '2025-09-08 21:32:39'),
(138, 1, 11, 'transfert', 'depot', 1, 'chantier', 1, 2, 'valide', NULL, 3, 4, '2025-09-08 21:33:12'),
(139, 1, 11, 'transfert', 'chantier', 1, 'depot', 1, 2, 'refuse', NULL, NULL, 6, '2025-09-08 21:34:24'),
(140, 1, 11, 'transfert', 'chantier', 1, 'depot', 1, 2, 'valide', NULL, 4, 6, '2025-09-08 21:34:49'),
(141, 1, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-09-20 19:35:33'),
(142, 1, 15, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-09-20 19:35:54'),
(143, 1, 11, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-09-20 19:36:26'),
(144, 1, 15, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-09-20 19:36:27'),
(145, 1, 25, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-10-05 17:21:16'),
(153, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 4, '2025-10-05 21:45:27'),
(154, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 4, 3, '2025-10-05 21:49:59'),
(155, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 4, '2025-10-05 21:50:22'),
(156, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-10-05 21:52:52'),
(157, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 4, '2025-10-05 21:53:20'),
(158, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 4, 3, '2025-10-05 21:55:25'),
(159, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 4, '2025-10-05 21:56:15'),
(160, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-10-05 21:57:56'),
(161, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 4, '2025-10-05 22:00:12'),
(162, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 4, 3, '2025-10-05 22:00:55'),
(163, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 4, '2025-10-05 22:01:32'),
(164, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 4, 3, '2025-10-05 22:04:58'),
(165, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 4, '2025-10-05 22:14:27'),
(166, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 4, 6, '2025-10-05 22:14:50'),
(167, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 6, 4, '2025-10-05 22:15:40'),
(168, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-10-06 21:09:51'),
(169, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 4, '2025-10-06 21:10:14'),
(170, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 6, '2025-10-06 21:24:51'),
(171, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 4, '2025-10-06 21:26:19'),
(172, 1, 32, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 4, 6, '2025-10-06 21:34:14'),
(173, 1, 32, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 6, 4, '2025-10-06 21:37:19');

-- --------------------------------------------------------

--
-- Structure de la table `transferts_en_attente`
--

CREATE TABLE `transferts_en_attente` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `article_id` int(11) NOT NULL,
  `source_type` enum('depot','chantier') NOT NULL,
  `source_id` int(11) NOT NULL,
  `destination_type` enum('depot','chantier') NOT NULL,
  `destination_id` int(11) NOT NULL,
  `quantite` int(11) NOT NULL,
  `demandeur_id` int(11) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'en_attente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

CREATE TABLE `utilisateurs` (
  `id` int(11) NOT NULL,
  `nom` varchar(255) NOT NULL,
  `prenom` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `motDePasse` varchar(255) DEFAULT NULL,
  `fonction` enum('administrateur','depot','chef','employe','autre') NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `agence_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`id`, `nom`, `prenom`, `email`, `motDePasse`, `fonction`, `entreprise_id`, `photo`, `created_at`, `agence_id`) VALUES
(3, 'Rodrigues', 'Anthony', 'anthony-rodrigues31@hotmail.fr', '$2y$10$C4KMy.RRbOE.V4M7puAda.5gcTKDZVYZ4lo4eL0KDfjtoHO2NNnI.', 'administrateur', 1, '/uploads/photos/687ce56a7631d_anthony.jpg', '2025-07-17 18:37:12', 10),
(4, 'Rodrigues', 'Ana', 'user@hotmail.fr', '$2y$10$WDIDL8RdOUYD0gGKRrMKkOjWOHB1OXcxH58ZrIEwAbrreTi8Y4fRW', 'chef', 1, NULL, '2025-07-17 20:28:19', 10),
(6, 'Rodrigues', 'Sam', 'anthonyrodrigues0512@gmail.com', '$2y$10$tSVxg90G0qj96s7beBd6tuSMQn9P69MWaivTwKfvypRPQIuspJKgC', 'depot', 1, NULL, '2025-07-17 20:29:17', 10),
(8, 'Bachar', 'Younes', 'user@test.com', '$2y$10$oSV7WwxVwMwPpw8.JnsRF.aiE915VUStRGeed/07BiFmHRJN/pyZO', 'depot', 1, NULL, '2025-08-19 19:36:28', 11),
(16, 'Jalran', 'Maxime', 'max@email.fr', '$2y$10$2aCnOLTQq0AiyKrz1TrMp.nmAbwGX.P12S8oAhfYyUhGLw.Tv6kF.', 'employe', 1, NULL, '2025-09-04 16:47:35', 10),
(17, 'Davy', 'Alex', 'alex@email.fr', '$2y$10$AyUfRT0gzdjlzZ28uXrX.eb0gSNF01r0.MsAmNSLgbqCqn.dzLXFi', 'chef', 1, NULL, '2025-09-27 12:26:24', 10);

-- --------------------------------------------------------

--
-- Structure de la table `utilisateur_chantiers`
--

CREATE TABLE `utilisateur_chantiers` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `chantier_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateur_chantiers`
--

INSERT INTO `utilisateur_chantiers` (`id`, `entreprise_id`, `utilisateur_id`, `chantier_id`) VALUES
(95, 1, 4, 1),
(110, 1, 17, 36);

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `agences`
--
ALTER TABLE `agences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_agence` (`entreprise_id`,`nom`);

--
-- Index pour la table `article_etats`
--
ALTER TABLE `article_etats`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `chantiers`
--
ALTER TABLE `chantiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chantiers_entreprise` (`entreprise_id`),
  ADD KEY `fk_chantiers_responsable` (`responsable_id`),
  ADD KEY `fk_chantiers_depot` (`depot_id`),
  ADD KEY `idx_chantiers_agence_id` (`agence_id`),
  ADD KEY `idx_chantiers_etat` (`etat`);

--
-- Index pour la table `chantier_taches`
--
ALTER TABLE `chantier_taches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ct` (`entreprise_id`,`chantier_id`,`nom`),
  ADD KEY `idx_ct_chantier` (`chantier_id`);

--
-- Index pour la table `depots`
--
ALTER TABLE `depots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `responsable_id` (`responsable_id`),
  ADD KEY `idx_depots_entreprise` (`entreprise_id`),
  ADD KEY `idx_depots_agence_id` (`agence_id`);

--
-- Index pour la table `entreprises`
--
ALTER TABLE `entreprises`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `utilisateur_id` (`utilisateur_id`),
  ADD KEY `idx_notif_entreprise` (`entreprise_id`),
  ADD KEY `idx_notifications_stock_id` (`stock_id`);

--
-- Index pour la table `planning_affectations`
--
ALTER TABLE `planning_affectations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_planning_affectations` (`entreprise_id`,`utilisateur_id`,`date_jour`),
  ADD UNIQUE KEY `uniq_affect` (`entreprise_id`,`utilisateur_id`,`date_jour`),
  ADD KEY `idx_pa_entreprise` (`entreprise_id`),
  ADD KEY `idx_pa_utilisateur` (`utilisateur_id`),
  ADD KEY `idx_pa_chantier` (`chantier_id`),
  ADD KEY `idx_pa_entreprise_date` (`entreprise_id`,`date_jour`);

--
-- Index pour la table `pointages_absences`
--
ALTER TABLE `pointages_absences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_abs` (`entreprise_id`,`utilisateur_id`,`date_jour`),
  ADD KEY `idx_ent_date` (`entreprise_id`,`date_jour`);

--
-- Index pour la table `pointages_camions`
--
ALTER TABLE `pointages_camions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq` (`entreprise_id`,`chantier_id`,`date_jour`);

--
-- Index pour la table `pointages_conduite`
--
ALTER TABLE `pointages_conduite`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_conduite` (`entreprise_id`,`utilisateur_id`,`chantier_id`,`date_pointage`,`type`),
  ADD KEY `idx_ent_date` (`entreprise_id`,`date_pointage`);

--
-- Index pour la table `pointages_jour`
--
ALTER TABLE `pointages_jour`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_presence` (`entreprise_id`,`utilisateur_id`,`date_jour`),
  ADD UNIQUE KEY `ux_pj_eid_cid_uid_date` (`entreprise_id`,`chantier_id`,`utilisateur_id`,`date_jour`),
  ADD KEY `idx_ent_date` (`entreprise_id`,`date_jour`),
  ADD KEY `idx_pj_tache` (`tache_id`);

--
-- Index pour la table `pointages_taches`
--
ALTER TABLE `pointages_taches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_chantier_date` (`utilisateur_id`,`chantier_id`,`date_jour`),
  ADD KEY `idx_entreprise` (`entreprise_id`),
  ADD KEY `idx_tache` (`tache_id`),
  ADD KEY `idx_eid_date` (`entreprise_id`,`date_jour`),
  ADD KEY `idx_eid_chantier` (`entreprise_id`,`chantier_id`);

--
-- Index pour la table `pointage_camions_cfg`
--
ALTER TABLE `pointage_camions_cfg`
  ADD PRIMARY KEY (`entreprise_id`,`chantier_id`);

--
-- Index pour la table `stock`
--
ALTER TABLE `stock`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `idx_stock_entreprise` (`entreprise_id`);

--
-- Index pour la table `stock_alerts`
--
ALTER TABLE `stock_alerts`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `stock_chantiers`
--
ALTER TABLE `stock_chantiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chantier_id` (`chantier_id`),
  ADD KEY `stock_id` (`stock_id`),
  ADD KEY `idx_sc_entreprise` (`entreprise_id`);

--
-- Index pour la table `stock_depots`
--
ALTER TABLE `stock_depots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_sd` (`entreprise_id`,`depot_id`,`stock_id`),
  ADD KEY `depot_id` (`depot_id`),
  ADD KEY `stock_id` (`stock_id`),
  ADD KEY `idx_sd_entreprise` (`entreprise_id`);

--
-- Index pour la table `stock_documents`
--
ALTER TABLE `stock_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stock_path` (`stock_id`,`chemin_fichier`),
  ADD KEY `idx_stock_id_created_at` (`stock_id`,`created_at`),
  ADD KEY `idx_checksum` (`checksum_sha1`),
  ADD KEY `fk_stock_documents_user` (`uploaded_by`),
  ADD KEY `idx_sdocs_entreprise` (`entreprise_id`);

--
-- Index pour la table `stock_hour_logs`
--
ALTER TABLE `stock_hour_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `stock_id` (`stock_id`),
  ADD KEY `chantier_id` (`chantier_id`),
  ADD KEY `utilisateur_id` (`utilisateur_id`);

--
-- Index pour la table `stock_mouvements`
--
ALTER TABLE `stock_mouvements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `stock_id` (`stock_id`,`created_at`),
  ADD KEY `idx_sm_entreprise` (`entreprise_id`);

--
-- Index pour la table `transferts_en_attente`
--
ALTER TABLE `transferts_en_attente`
  ADD PRIMARY KEY (`id`),
  ADD KEY `article_id` (`article_id`),
  ADD KEY `demandeur_id` (`demandeur_id`),
  ADD KEY `idx_tea_entreprise` (`entreprise_id`);

--
-- Index pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_utilisateurs_agence` (`agence_id`),
  ADD KEY `idx_utilisateurs_entreprise` (`entreprise_id`);

--
-- Index pour la table `utilisateur_chantiers`
--
ALTER TABLE `utilisateur_chantiers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `utilisateur_id` (`utilisateur_id`,`chantier_id`),
  ADD KEY `chantier_id` (`chantier_id`),
  ADD KEY `idx_uc_entreprise` (`entreprise_id`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `agences`
--
ALTER TABLE `agences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT pour la table `article_etats`
--
ALTER TABLE `article_etats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT pour la table `chantiers`
--
ALTER TABLE `chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT pour la table `chantier_taches`
--
ALTER TABLE `chantier_taches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT pour la table `depots`
--
ALTER TABLE `depots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT pour la table `entreprises`
--
ALTER TABLE `entreprises`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=319;

--
-- AUTO_INCREMENT pour la table `planning_affectations`
--
ALTER TABLE `planning_affectations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=868;

--
-- AUTO_INCREMENT pour la table `pointages_absences`
--
ALTER TABLE `pointages_absences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

--
-- AUTO_INCREMENT pour la table `pointages_camions`
--
ALTER TABLE `pointages_camions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT pour la table `pointages_conduite`
--
ALTER TABLE `pointages_conduite`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT pour la table `pointages_jour`
--
ALTER TABLE `pointages_jour`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=307;

--
-- AUTO_INCREMENT pour la table `pointages_taches`
--
ALTER TABLE `pointages_taches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT pour la table `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT pour la table `stock_alerts`
--
ALTER TABLE `stock_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT pour la table `stock_chantiers`
--
ALTER TABLE `stock_chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT pour la table `stock_depots`
--
ALTER TABLE `stock_depots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT pour la table `stock_documents`
--
ALTER TABLE `stock_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT pour la table `stock_hour_logs`
--
ALTER TABLE `stock_hour_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT pour la table `stock_mouvements`
--
ALTER TABLE `stock_mouvements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=174;

--
-- AUTO_INCREMENT pour la table `transferts_en_attente`
--
ALTER TABLE `transferts_en_attente`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=316;

--
-- AUTO_INCREMENT pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT pour la table `utilisateur_chantiers`
--
ALTER TABLE `utilisateur_chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `chantiers`
--
ALTER TABLE `chantiers`
  ADD CONSTRAINT `fk_chantiers_agence` FOREIGN KEY (`agence_id`) REFERENCES `agences` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_chantiers_depot` FOREIGN KEY (`depot_id`) REFERENCES `depots` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_chantiers_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_chantiers_responsable` FOREIGN KEY (`responsable_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `chantier_taches`
--
ALTER TABLE `chantier_taches`
  ADD CONSTRAINT `fk_ct_chantier` FOREIGN KEY (`chantier_id`) REFERENCES `chantiers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `depots`
--
ALTER TABLE `depots`
  ADD CONSTRAINT `depots_ibfk_1` FOREIGN KEY (`responsable_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_depots_agence` FOREIGN KEY (`agence_id`) REFERENCES `agences` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_depots_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON UPDATE CASCADE;

--
-- Contraintes pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notifications_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `planning_affectations`
--
ALTER TABLE `planning_affectations`
  ADD CONSTRAINT `fk_pa_chantier` FOREIGN KEY (`chantier_id`) REFERENCES `chantiers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pa_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pa_utilisateur` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `pointages_jour`
--
ALTER TABLE `pointages_jour`
  ADD CONSTRAINT `fk_pj_tache` FOREIGN KEY (`tache_id`) REFERENCES `chantier_taches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `stock`
--
ALTER TABLE `stock`
  ADD CONSTRAINT `fk_stock_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON UPDATE CASCADE;

--
-- Contraintes pour la table `stock_chantiers`
--
ALTER TABLE `stock_chantiers`
  ADD CONSTRAINT `fk_sc_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_chantiers_ibfk_1` FOREIGN KEY (`chantier_id`) REFERENCES `chantiers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_chantiers_ibfk_2` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `stock_depots`
--
ALTER TABLE `stock_depots`
  ADD CONSTRAINT `fk_sd_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_depots_ibfk_1` FOREIGN KEY (`depot_id`) REFERENCES `depots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_depots_ibfk_2` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `stock_documents`
--
ALTER TABLE `stock_documents`
  ADD CONSTRAINT `fk_sdocs_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_stock_documents_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stock_documents_user` FOREIGN KEY (`uploaded_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `stock_hour_logs`
--
ALTER TABLE `stock_hour_logs`
  ADD CONSTRAINT `fk_shl_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `stock_mouvements`
--
ALTER TABLE `stock_mouvements`
  ADD CONSTRAINT `fk_sm_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sm_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `transferts_en_attente`
--
ALTER TABLE `transferts_en_attente`
  ADD CONSTRAINT `fk_tea_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `transferts_en_attente_ibfk_1` FOREIGN KEY (`article_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transferts_en_attente_ibfk_2` FOREIGN KEY (`demandeur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD CONSTRAINT `fk_utilisateurs_agence` FOREIGN KEY (`agence_id`) REFERENCES `agences` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_utilisateurs_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON UPDATE CASCADE;

--
-- Contraintes pour la table `utilisateur_chantiers`
--
ALTER TABLE `utilisateur_chantiers`
  ADD CONSTRAINT `utilisateur_chantiers_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `utilisateur_chantiers_ibfk_2` FOREIGN KEY (`chantier_id`) REFERENCES `chantiers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
