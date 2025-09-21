-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : sam. 20 sep. 2025 à 22:34
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
-- Structure de la table `chantiers`
--

CREATE TABLE `chantiers` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `nom` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `responsable_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `chantiers`
--

INSERT INTO `chantiers` (`id`, `entreprise_id`, `nom`, `description`, `date_debut`, `date_fin`, `responsable_id`, `created_at`) VALUES
(1, 1, 'Bouchait', '', NULL, NULL, 4, '2025-07-17 20:27:49'),
(2, 1, 'Coma', '', NULL, NULL, 5, '2025-07-17 20:27:49');

-- --------------------------------------------------------

--
-- Structure de la table `chantier_taches`
--

CREATE TABLE `chantier_taches` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) NOT NULL,
  `chantier_id` int(11) NOT NULL,
  `nom` varchar(190) NOT NULL,
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

INSERT INTO `chantier_taches` (`id`, `entreprise_id`, `chantier_id`, `nom`, `unite`, `quantite`, `tu_heures`, `tu_minutes`, `avancement_pct`, `created_at`, `updated_at`) VALUES
(9, 1, 1, 'depose ouvrage de structure2', 'm2', 1207.50, 0.33, 20.00, 0, '2025-09-20 22:24:02', '2025-09-20 22:34:52');

-- --------------------------------------------------------

--
-- Structure de la table `depots`
--

CREATE TABLE `depots` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `nom` varchar(255) NOT NULL,
  `responsable_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `depots`
--

INSERT INTO `depots` (`id`, `entreprise_id`, `nom`, `responsable_id`, `created_at`) VALUES
(1, 1, 'Montréjeau', 6, '2025-07-17 20:31:20'),
(2, 1, 'Toulouse', 8, '2025-08-19 19:38:14');

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
  `lu` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notifications`
--

INSERT INTO `notifications` (`id`, `entreprise_id`, `utilisateur_id`, `message`, `lu`, `created_at`) VALUES
(254, 1, 3, '✅ Le transfert de 1 x Banche 2m40 fhd a été validé par l\'administrateur.', 0, '2025-09-07 19:37:55'),
(255, 1, 3, '✅ Le transfert de 1 x Banche 2m40 fhd a été validé par l\'administrateur.', 0, '2025-09-07 19:48:25'),
(256, 1, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-09-07 19:49:05'),
(257, 1, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-09-07 19:49:20'),
(260, 1, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-09-07 20:04:12'),
(272, 1, 3, '✅ Le transfert de 3 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-09-08 17:47:47'),
(273, 1, 3, '✅ Le transfert de 10 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-09-08 19:10:25'),
(274, 1, 3, '✅ Le transfert de 10 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-09-08 19:10:40'),
(275, 1, 3, '✅ Le transfert de 4 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-09-08 19:10:51'),
(278, 1, 4, '✅ Le transfert de 5 x Banche 2m40 a été validé par le dépôt.', 0, '2025-09-08 19:29:08'),
(280, 1, 6, '❌ Le chantier a refusé le transfert de 1 x Banche 2m40.', 0, '2025-09-08 19:32:05'),
(281, 1, 3, '❌ Le chantier a refusé le transfert de 1 x Banche 2m40.', 0, '2025-09-08 19:32:39'),
(282, 1, 4, '❌ Le dépôt a refusé le transfert de 2 x Banche 2m40.', 0, '2025-09-08 19:34:24'),
(283, 1, 4, '✅ Le transfert de 2 x Banche 2m40 a été validé par le dépôt.', 0, '2025-09-08 19:34:49'),
(284, 1, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-09-20 17:35:33'),
(285, 1, 3, '✅ Le transfert de 1 x Plateau 3m a été validé par l\'administrateur.', 0, '2025-09-20 17:35:54'),
(286, 1, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-09-20 17:36:26'),
(287, 1, 3, '✅ Le transfert de 1 x Plateau 3m a été validé par l\'administrateur.', 0, '2025-09-20 17:36:27');

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
  `type` enum('chantier','depot') NOT NULL DEFAULT 'chantier',
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
(138, 1, 5, 2, NULL, '2025-09-01', 'chantier', 'employe', 0.00, NULL, '2025-09-05 20:29:52', 0),
(173, 1, 5, 2, NULL, '2025-09-09', 'chantier', 'employe', 0.00, NULL, '2025-09-08 20:05:12', 1),
(174, 1, 5, 2, NULL, '2025-09-10', 'chantier', 'employe', 0.00, NULL, '2025-09-08 20:05:12', 1),
(175, 1, 5, 2, NULL, '2025-09-11', 'chantier', 'employe', 0.00, NULL, '2025-09-08 20:05:12', 1),
(294, 1, 4, 1, NULL, '2025-09-08', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:19', 0),
(296, 1, 4, 1, NULL, '2025-09-10', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:19', 0),
(297, 1, 4, 1, NULL, '2025-09-11', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:19', 0),
(298, 1, 4, 1, NULL, '2025-09-12', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:19', 1),
(303, 1, 16, 2, NULL, '2025-09-08', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:52', 1),
(304, 1, 16, 2, NULL, '2025-09-09', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:52', 0),
(305, 1, 16, 2, NULL, '2025-09-10', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:52', 1),
(306, 1, 16, 2, NULL, '2025-09-11', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:53', 1),
(307, 1, 16, 2, NULL, '2025-09-12', 'chantier', 'employe', 0.00, NULL, '2025-09-13 13:51:53', 1),
(394, 1, 4, 1, NULL, '2025-09-15', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:42', 1),
(395, 1, 4, 1, NULL, '2025-09-16', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:43', 0),
(396, 1, 4, 1, NULL, '2025-09-17', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:43', 0),
(397, 1, 4, 1, NULL, '2025-09-18', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:43', 0),
(398, 1, 4, 1, NULL, '2025-09-19', 'chantier', 'employe', 0.00, NULL, '2025-09-14 11:54:43', 1),
(451, 1, 4, 1, NULL, '2025-09-09', 'chantier', 'employe', 0.00, NULL, '2025-09-14 14:24:07', 0),
(482, 1, 5, 2, NULL, '2025-09-12', 'chantier', 'employe', 0.00, NULL, '2025-09-14 15:17:48', 1),
(490, 1, 5, 2, NULL, '2025-09-08', 'chantier', 'employe', 0.00, NULL, '2025-09-14 15:34:50', 1),
(505, 1, 5, 2, NULL, '2025-09-15', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:26', 1),
(506, 1, 5, 2, NULL, '2025-09-16', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:26', 1),
(507, 1, 5, 2, NULL, '2025-09-17', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:26', 1),
(508, 1, 5, 2, NULL, '2025-09-18', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:27', 1),
(509, 1, 5, 2, NULL, '2025-09-19', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:27', 1),
(511, 1, 16, 2, NULL, '2025-09-15', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:32', 1),
(512, 1, 16, 2, NULL, '2025-09-16', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:32', 1),
(513, 1, 16, 2, NULL, '2025-09-17', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:32', 1),
(514, 1, 16, 2, NULL, '2025-09-18', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:33', 1),
(515, 1, 16, 2, NULL, '2025-09-19', 'chantier', 'employe', 0.00, NULL, '2025-09-16 19:27:33', 1),
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
(533, 1, 16, 1, NULL, '2025-09-20', 'chantier', 'employe', 0.00, NULL, '2025-09-20 17:26:21', 1),
(535, 1, 4, 1, NULL, '2025-09-20', 'chantier', 'employe', 0.00, NULL, '2025-09-20 17:26:23', 1);

-- --------------------------------------------------------

--
-- Structure de la table `pointages_absences`
--

CREATE TABLE `pointages_absences` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `date_jour` date NOT NULL,
  `motif` enum('conges','maladie','injustifie') NOT NULL,
  `heures` decimal(5,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `pointages_absences`
--

INSERT INTO `pointages_absences` (`id`, `entreprise_id`, `utilisateur_id`, `date_jour`, `motif`, `heures`, `created_at`, `updated_at`) VALUES
(37, 1, 16, '2025-09-18', 'injustifie', 4.00, '2025-09-18 21:33:56', '2025-09-18 21:33:56'),
(38, 1, 5, '2025-09-18', 'injustifie', 3.00, '2025-09-18 21:40:34', '2025-09-18 21:40:34');

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
(51, 1, 16, '2025-09-18', 2, NULL, 4.25, '2025-09-18 21:33:56'),
(52, 1, 5, '2025-09-18', 2, NULL, 5.25, '2025-09-18 21:40:34');

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
(1, 1, 2),
(1, 2, 2);

-- --------------------------------------------------------

--
-- Structure de la table `stock`
--

CREATE TABLE `stock` (
  `id` int(11) NOT NULL,
  `entreprise_id` int(10) UNSIGNED NOT NULL,
  `nom` varchar(255) NOT NULL,
  `quantite_totale` int(11) DEFAULT 0,
  `quantite_disponible` int(11) DEFAULT 0,
  `categorie` varchar(255) DEFAULT NULL,
  `sous_categorie` varchar(255) DEFAULT NULL,
  `dimensions` varchar(100) DEFAULT NULL,
  `poids` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `depot_id` int(11) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `document` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock`
--

INSERT INTO `stock` (`id`, `entreprise_id`, `nom`, `quantite_totale`, `quantite_disponible`, `categorie`, `sous_categorie`, `dimensions`, `poids`, `created_at`, `depot_id`, `photo`, `document`) VALUES
(2, 1, 'banche 2m75', 24, 24, 'Banches', 'Manuportables', NULL, NULL, '2025-07-17 20:31:41', NULL, 'uploads/photos/articles/2/68acb990e8a8e5.25520685.jpg', NULL),
(10, 1, 'Piqueur', 23, 23, 'Electroportatif', 'Piquage', NULL, NULL, '2025-07-27 21:08:02', NULL, 'uploads/photos/articles/10/68a6d2139ffb16.19401476.jpg', NULL),
(11, 1, 'Banche 2m40', 20, 20, 'Banches', 'Metalique', NULL, NULL, '2025-08-19 08:56:10', NULL, 'uploads/photos/articles/11/68a70b96268571.58204439.jpg', NULL),
(12, 1, 'Element blanc', 100, 100, 'Peri', NULL, NULL, NULL, '2025-08-21 17:02:15', NULL, 'uploads/photos/articles/12/68a751c0846558.19603577.png', NULL),
(15, 1, 'Plateau 3m', 100, 100, 'Echafaudage', '3m', NULL, NULL, '2025-09-07 10:13:23', NULL, NULL, NULL);

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
(49, 1, 1, 15, 0, '2025-09-20 17:35:54');

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
(19, 1, 1, 15, 100);

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
(144, 1, 15, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-09-20 19:36:27');

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
(5, 'Boya', 'Stéphanie', 'bambina.31@hotmail.fr', '$2y$10$TzQr83yoAtF0YmQgh7n8qeHIL3z5C23/LPfS86VZkXZ.Nu/F9xRoS', 'chef', 1, NULL, '2025-07-17 20:29:00', 10),
(6, 'Rodrigues', 'Sam', 'anthonyrodrigues0512@gmail.com', '$2y$10$tSVxg90G0qj96s7beBd6tuSMQn9P69MWaivTwKfvypRPQIuspJKgC', 'depot', 1, NULL, '2025-07-17 20:29:17', 10),
(8, 'Bachar', 'Younes', 'user@test.com', '$2y$10$oSV7WwxVwMwPpw8.JnsRF.aiE915VUStRGeed/07BiFmHRJN/pyZO', 'depot', 1, NULL, '2025-08-19 19:36:28', 11),
(16, 'Jalran', 'Maxime', 'max@email.fr', '$2y$10$2aCnOLTQq0AiyKrz1TrMp.nmAbwGX.P12S8oAhfYyUhGLw.Tv6kF.', 'employe', 1, NULL, '2025-09-04 16:47:35', 10);

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
(52, 1, 4, 1),
(56, 1, 5, 2);

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
-- Index pour la table `chantiers`
--
ALTER TABLE `chantiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chantiers_entreprise` (`entreprise_id`),
  ADD KEY `fk_chantiers_responsable` (`responsable_id`);

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
  ADD KEY `idx_depots_entreprise` (`entreprise_id`);

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
  ADD KEY `idx_notif_entreprise` (`entreprise_id`);

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
  ADD KEY `idx_ent_date` (`entreprise_id`,`date_jour`),
  ADD KEY `idx_pj_tache` (`tache_id`);

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
  ADD KEY `idx_stock_entreprise` (`entreprise_id`);

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
-- AUTO_INCREMENT pour la table `chantiers`
--
ALTER TABLE `chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT pour la table `chantier_taches`
--
ALTER TABLE `chantier_taches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT pour la table `depots`
--
ALTER TABLE `depots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT pour la table `entreprises`
--
ALTER TABLE `entreprises`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=288;

--
-- AUTO_INCREMENT pour la table `planning_affectations`
--
ALTER TABLE `planning_affectations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=537;

--
-- AUTO_INCREMENT pour la table `pointages_absences`
--
ALTER TABLE `pointages_absences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT pour la table `pointages_camions`
--
ALTER TABLE `pointages_camions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT pour la table `pointages_conduite`
--
ALTER TABLE `pointages_conduite`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT pour la table `pointages_jour`
--
ALTER TABLE `pointages_jour`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT pour la table `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT pour la table `stock_chantiers`
--
ALTER TABLE `stock_chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT pour la table `stock_depots`
--
ALTER TABLE `stock_depots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT pour la table `stock_documents`
--
ALTER TABLE `stock_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT pour la table `stock_mouvements`
--
ALTER TABLE `stock_mouvements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=145;

--
-- AUTO_INCREMENT pour la table `transferts_en_attente`
--
ALTER TABLE `transferts_en_attente`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=287;

--
-- AUTO_INCREMENT pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT pour la table `utilisateur_chantiers`
--
ALTER TABLE `utilisateur_chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `chantiers`
--
ALTER TABLE `chantiers`
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
  ADD CONSTRAINT `fk_depots_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON UPDATE CASCADE;

--
-- Contraintes pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_entreprise` FOREIGN KEY (`entreprise_id`) REFERENCES `entreprises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
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
