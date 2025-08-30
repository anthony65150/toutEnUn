-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : sam. 30 août 2025 à 22:06
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
(4, 0, 'Montréjeau', NULL, 1, '2025-08-30 19:16:22'),
(5, 0, 'Toulouse', NULL, 1, '2025-08-30 19:47:18');

-- --------------------------------------------------------

--
-- Structure de la table `chantiers`
--

CREATE TABLE `chantiers` (
  `id` int(11) NOT NULL,
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

INSERT INTO `chantiers` (`id`, `nom`, `description`, `date_debut`, `date_fin`, `responsable_id`, `created_at`) VALUES
(1, 'Bouchait', '', NULL, NULL, 4, '2025-07-17 20:27:49'),
(2, 'Coma', '', NULL, NULL, 5, '2025-07-17 20:27:49');

-- --------------------------------------------------------

--
-- Structure de la table `depots`
--

CREATE TABLE `depots` (
  `id` int(11) NOT NULL,
  `nom` varchar(255) NOT NULL,
  `responsable_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `depots`
--

INSERT INTO `depots` (`id`, `nom`, `responsable_id`, `created_at`) VALUES
(1, 'Montréjeau', 6, '2025-07-17 20:31:20'),
(2, 'Toulouse', 8, '2025-08-19 19:38:14');

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `lu` tinyint(4) NOT NULL DEFAULT 0,
  `created-at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notifications`
--

INSERT INTO `notifications` (`id`, `utilisateur_id`, `message`, `lu`, `created-at`) VALUES
(100, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 19:48:06'),
(101, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 19:52:03'),
(102, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 19:56:26'),
(103, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 20:00:59'),
(104, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 20:07:08'),
(105, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 20:07:43'),
(106, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 20:13:07'),
(107, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 20:21:41'),
(108, 3, '✅ Le transfert de 10 x banche 2m75 a été validé par le chantier.', 0, '2025-07-25 20:33:23'),
(109, 3, '✅ Le transfert de 10 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-07-25 20:36:51'),
(110, 3, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-25 20:37:08'),
(111, 6, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-25 20:39:26'),
(112, 3, '✅ Le transfert de 20 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 20:44:45'),
(113, 3, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-25 20:45:09'),
(114, 6, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-25 20:46:55'),
(115, 3, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-25 20:50:07'),
(116, 4, '✅ Le transfert de 30 x plateau 3m a été validé par le dépôt.', 0, '2025-07-25 20:56:40'),
(117, 6, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-25 20:56:57'),
(118, 4, '✅ Le transfert de 10 x plateau 3m a été validé par le dépôt.', 0, '2025-07-25 21:00:29'),
(119, 3, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-25 21:01:57'),
(120, 4, '✅ Le transfert de 10 x plateau 3m a été validé par le dépôt.', 0, '2025-07-25 21:02:18'),
(121, 6, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-25 21:03:18'),
(122, 6, '✅ Le transfert de 1 x banche 2m75 a été validé par le chantier.', 0, '2025-07-25 21:03:25'),
(123, 3, '✅ Le transfert de 1 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-07-25 21:04:16'),
(124, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-25 21:04:17'),
(125, 3, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-27 14:19:49'),
(126, 3, '✅ Le transfert de 10 x plateau 3m a été validé par le chantier.', 0, '2025-07-27 14:19:55'),
(127, 5, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-27 14:20:47'),
(128, 5, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-27 14:20:48'),
(129, 6, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-27 14:43:09'),
(130, 6, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-27 14:53:23'),
(131, 6, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-27 14:53:24'),
(132, 3, '✅ Le transfert de 1 x Piqueur a été validé par l\'administrateur.', 0, '2025-07-27 14:59:21'),
(133, 3, '✅ Le transfert de 30 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-27 14:59:22'),
(134, 3, '✅ Le transfert de 1 x Piqueur a été validé par l\'administrateur.', 0, '2025-07-27 14:59:35'),
(135, 3, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-28 20:51:04'),
(136, 4, '✅ Le transfert de 10 x plateau 3m a été validé par l\'administrateur.', 0, '2025-07-28 20:51:37'),
(137, 3, '✅ Le transfert de 2 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-07-29 19:36:57'),
(138, 3, '✅ Le transfert de 2 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-07-31 19:13:59'),
(139, 3, '✅ Le transfert de 13 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-07-31 19:15:52'),
(140, 3, '✅ Le transfert de 13 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-07-31 19:16:24'),
(141, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 09:02:17'),
(142, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 09:02:56'),
(143, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 12:19:16'),
(144, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 12:23:35'),
(145, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 12:35:20'),
(146, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 12:35:55'),
(147, 3, '❌ Le transfert de 10 x Banche 2m40 a été annulé par l’administrateur.', 0, '2025-08-19 12:36:20'),
(148, 3, '✅ Le transfert de 5 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-19 12:38:39'),
(149, 4, '✅ Le transfert de 5 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-19 12:41:04'),
(150, 3, '✅ Le transfert de 2 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 12:45:12'),
(151, 3, '✅ Le transfert de 2 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 12:49:18'),
(152, 3, '❌ Le transfert de 10 x Banche 2m40 a été annulé par l’administrateur.', 0, '2025-08-19 12:50:52'),
(153, 3, '❌ Le transfert de 10 x Banche 2m40 a été annulé par l’administrateur.', 0, '2025-08-19 12:56:59'),
(154, 3, '❌ Le chantier a refusé le transfert de 10 x Banche 2m40.', 0, '2025-08-19 12:57:16'),
(155, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-19 13:01:55'),
(156, 4, '✅ Le transfert de 5 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-19 13:04:24'),
(157, 4, '✅ Le transfert de 5 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 13:04:47'),
(158, 6, '❌ Le chantier a refusé le transfert de 7 x Banche 2m40.', 0, '2025-08-19 13:06:31'),
(159, 3, '✅ Le transfert de 5 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 13:07:37'),
(160, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-19 13:09:48'),
(161, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 13:10:27'),
(162, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 13:17:15'),
(163, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 13:17:37'),
(164, 6, '✅ Le transfert de 10 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-19 17:04:02'),
(165, 4, '✅ Le transfert de 10 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-19 17:06:55'),
(166, 6, '✅ Le transfert de 5 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-19 17:07:35'),
(167, 3, '✅ Le transfert de 5 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 17:18:24'),
(168, 3, '✅ Le transfert de 24 x banche 2m75 a été validé par le chantier.', 0, '2025-08-19 17:29:49'),
(169, 4, '✅ Le transfert de 24 x banche 2m75 a été validé par le dépôt.', 0, '2025-08-19 17:36:50'),
(170, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-19 19:38:51'),
(171, 8, '✅ Le transfert de 10 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-19 19:39:57'),
(172, 6, '✅ Le transfert de 10 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-19 20:15:25'),
(173, 8, '✅ Le transfert de 10 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-20 07:28:49'),
(174, 6, '✅ Le transfert de 6 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-20 07:37:18'),
(175, 6, '✅ Le transfert de 6 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-20 07:41:10'),
(176, 8, '✅ Le transfert de 12 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-20 07:41:44'),
(177, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-20 07:57:30'),
(178, 3, '✅ Le transfert de 12 x banche 2m75 a été validé par le chantier.', 0, '2025-08-20 08:00:04'),
(179, 5, '✅ Le transfert de 12 x banche 2m75 a été validé par le dépôt.', 0, '2025-08-20 08:01:24'),
(180, 6, '❌ Le chantier a refusé le transfert de 12 x banche 2m75.', 0, '2025-08-20 08:01:49'),
(181, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-20 08:40:08'),
(182, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-21 08:00:39'),
(183, 3, '✅ Le transfert de 5 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-21 11:38:36'),
(184, 3, '✅ Le transfert de 5 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-21 12:02:30'),
(185, 3, '✅ Le transfert de 5 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-21 12:02:31'),
(186, 6, '✅ Le transfert de 10 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-21 12:41:16'),
(187, 4, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-21 12:53:13'),
(188, 6, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-21 13:22:35'),
(189, 3, '✅ Le transfert de 50 x Element blanc a été validé par l\'administrateur.', 0, '2025-08-21 17:05:55'),
(190, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-21 20:48:25'),
(191, 6, '✅ Le transfert de 1 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-21 20:51:14'),
(192, 4, '✅ Le transfert de 1 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-21 21:02:05'),
(193, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-21 21:03:40'),
(194, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-21 21:16:24'),
(195, 6, '✅ Le transfert de 10 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-21 21:21:12'),
(196, 4, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-22 12:37:15'),
(197, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-22 12:59:06'),
(198, 6, '✅ Le transfert de 10 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-08-22 13:01:45'),
(199, 3, '✅ Le transfert de 10 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-08-22 13:02:06'),
(200, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-22 13:02:30'),
(201, 3, '✅ Le transfert de 10 x Element blanc a été validé par l\'administrateur.', 0, '2025-08-22 13:02:32'),
(202, 3, '✅ Le transfert de 40 x Element blanc a été validé par l\'administrateur.', 0, '2025-08-22 13:02:48'),
(203, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 12:08:11'),
(204, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-23 12:09:09'),
(205, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-23 12:09:49'),
(206, 4, '✅ Le transfert de 1 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-23 12:09:50'),
(207, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 12:29:48'),
(208, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 12:33:30'),
(209, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 12:49:36'),
(210, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 13:05:25'),
(211, 6, '✅ Le transfert de 50 x Element blanc a été validé par le chantier.', 0, '2025-08-23 16:57:45'),
(212, 3, '✅ Le transfert de 50 x Element blanc a été validé par l\'administrateur.', 0, '2025-08-23 16:58:50'),
(213, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 17:02:26'),
(214, 3, '✅ Le transfert de 2 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 17:20:19'),
(215, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:50:03'),
(216, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:50:04'),
(217, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:50:05'),
(218, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:51:00'),
(219, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:51:02'),
(220, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:51:03'),
(221, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:51:04'),
(222, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:51:35'),
(223, 3, '✅ Le transfert de 2 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:51:36'),
(224, 3, '✅ Le transfert de 6 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:52:14'),
(225, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:57:01'),
(226, 3, '✅ Le transfert de 2 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:57:02'),
(227, 3, '✅ Le transfert de 2 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 18:57:03'),
(228, 3, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 19:31:14'),
(229, 3, '✅ Le transfert de 10 x Element blanc a été validé par l\'administrateur.', 0, '2025-08-23 19:31:40'),
(230, 4, '✅ Le transfert de 10 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 20:13:41'),
(231, 3, '✅ Le transfert de 5 x Element blanc a été validé par l\'administrateur.', 0, '2025-08-23 20:20:59'),
(232, 4, '✅ Le transfert de 5 x Element blanc a été validé par le dépôt.', 0, '2025-08-23 20:21:35'),
(233, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 20:22:17'),
(234, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 20:22:33'),
(235, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-23 20:22:51'),
(236, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-24 13:17:44'),
(237, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-24 19:38:14'),
(238, 3, '✅ Le transfert de 2 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-24 19:51:31'),
(239, 3, '✅ Le transfert de 1 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-08-25 19:24:24'),
(240, 6, '✅ Le transfert de 1 x banche 2m75 a été validé par le dépôt.', 0, '2025-08-25 19:25:27'),
(241, 6, '✅ Le transfert de 1 x Banche 2m40 a été validé par le chantier.', 0, '2025-08-25 19:25:52'),
(242, 8, '✅ Le transfert de 1 x banche 2m75 a été validé par le dépôt.', 0, '2025-08-25 19:27:52'),
(243, 3, '✅ Le transfert de 1 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-08-25 19:28:20'),
(244, 4, '✅ Le transfert de 3 x Banche 2m40 a été validé par le dépôt.', 0, '2025-08-25 19:34:31'),
(245, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-26 16:44:46'),
(246, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-28 20:33:40'),
(247, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-28 20:35:06'),
(248, 3, '❌ Le transfert de 1 x Banche 2m40 a été annulé par l’administrateur.', 0, '2025-08-28 20:49:02'),
(249, 3, '✅ Le transfert de 1 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-08-28 20:49:18'),
(250, 3, '✅ Le transfert de 1 x banche 2m75 a été validé par l\'administrateur.', 0, '2025-08-28 20:52:20'),
(251, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-28 20:52:21'),
(252, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-30 12:55:12'),
(253, 3, '✅ Le transfert de 1 x Banche 2m40 a été validé par l\'administrateur.', 0, '2025-08-30 12:56:34');

-- --------------------------------------------------------

--
-- Structure de la table `stock`
--

CREATE TABLE `stock` (
  `id` int(11) NOT NULL,
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

INSERT INTO `stock` (`id`, `nom`, `quantite_totale`, `quantite_disponible`, `categorie`, `sous_categorie`, `dimensions`, `poids`, `created_at`, `depot_id`, `photo`, `document`) VALUES
(2, 'banche 2m75', 24, 24, 'Banches', 'Manuportables', NULL, NULL, '2025-07-17 20:31:41', NULL, 'uploads/photos/articles/2/68acb990e8a8e5.25520685.jpg', NULL),
(10, 'Piqueur', 23, 23, 'Electroportatif', 'Piquage', NULL, NULL, '2025-07-27 21:08:02', NULL, 'uploads/photos/articles/10/68a6d2139ffb16.19401476.jpg', NULL),
(11, 'Banche 2m40', 20, 20, 'Banches', 'Metalique', NULL, NULL, '2025-08-19 08:56:10', NULL, 'uploads/photos/articles/11/68a70b96268571.58204439.jpg', NULL),
(12, 'Element blanc', 100, 100, 'Peri', NULL, NULL, NULL, '2025-08-21 17:02:15', NULL, 'uploads/photos/articles/12/68a751c0846558.19603577.png', NULL),
(14, 'Plateau 3m', 100, 100, 'Echafaudage', '3m', NULL, NULL, '2025-08-25 19:42:03', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `stock_chantiers`
--

CREATE TABLE `stock_chantiers` (
  `id` int(11) NOT NULL,
  `chantier_id` int(11) NOT NULL,
  `stock_id` int(11) NOT NULL,
  `quantite` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock_chantiers`
--

INSERT INTO `stock_chantiers` (`id`, `chantier_id`, `stock_id`, `quantite`, `created_at`) VALUES
(30, 1, 2, 0, '2025-07-25 20:33:23'),
(35, 1, 11, 0, '2025-08-19 09:02:17'),
(36, 2, 11, 0, '2025-08-19 13:04:47'),
(37, 2, 2, 0, '2025-08-20 08:00:04'),
(38, 1, 12, 0, '2025-08-23 16:57:45');

-- --------------------------------------------------------

--
-- Structure de la table `stock_depots`
--

CREATE TABLE `stock_depots` (
  `id` int(11) NOT NULL,
  `depot_id` int(11) NOT NULL,
  `stock_id` int(11) NOT NULL,
  `quantite` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock_depots`
--

INSERT INTO `stock_depots` (`id`, `depot_id`, `stock_id`, `quantite`) VALUES
(2, 1, 2, 24),
(10, 1, 10, 23),
(11, 1, 11, 20),
(12, 2, 11, 0),
(13, 1, 12, 100),
(14, 2, 12, 0),
(17, 2, 2, 0),
(18, 1, 14, 100);

-- --------------------------------------------------------

--
-- Structure de la table `stock_documents`
--

CREATE TABLE `stock_documents` (
  `id` int(11) NOT NULL,
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

INSERT INTO `stock_mouvements` (`id`, `stock_id`, `type`, `source_type`, `source_id`, `dest_type`, `dest_id`, `quantite`, `statut`, `commentaire`, `demandeur_id`, `utilisateur_id`, `created_at`) VALUES
(68, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-08-23 19:02:26'),
(69, 11, 'transfert', 'depot', 1, 'depot', 2, 2, 'valide', NULL, 3, 3, '2025-08-23 19:20:19'),
(70, 11, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-08-23 20:50:03'),
(71, 11, 'transfert', 'depot', 1, 'chantier', 2, 1, 'valide', NULL, 3, 3, '2025-08-23 20:50:04'),
(72, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-08-23 20:50:05'),
(73, 11, 'transfert', 'depot', 1, 'depot', 2, 1, 'valide', NULL, 3, 3, '2025-08-23 20:51:00'),
(74, 11, 'transfert', 'depot', 1, 'depot', 4, 1, 'valide', NULL, 3, 3, '2025-08-23 20:51:02'),
(75, 11, 'transfert', 'depot', 1, 'chantier', 2, 1, 'valide', NULL, 3, 3, '2025-08-23 20:51:03'),
(76, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-08-23 20:51:04'),
(77, 11, 'transfert', 'depot', 1, 'depot', 2, 1, 'valide', NULL, 3, 3, '2025-08-23 20:51:35'),
(78, 11, 'transfert', 'depot', 1, 'depot', 2, 2, 'valide', NULL, 3, 3, '2025-08-23 20:51:36'),
(79, 11, 'transfert', 'depot', 2, 'depot', 1, 6, 'valide', NULL, 3, 3, '2025-08-23 20:52:14'),
(80, 11, 'transfert', 'depot', 4, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-08-23 20:57:01'),
(81, 11, 'transfert', 'chantier', 2, 'depot', 1, 2, 'valide', NULL, 3, 3, '2025-08-23 20:57:02'),
(82, 11, 'transfert', 'chantier', 1, 'depot', 1, 2, 'valide', NULL, 3, 3, '2025-08-23 20:57:03'),
(83, 11, 'transfert', 'depot', 1, 'chantier', 1, 10, 'valide', NULL, 3, 3, '2025-08-23 21:31:14'),
(84, 12, 'transfert', 'depot', 1, 'chantier', 1, 10, 'valide', NULL, 3, 3, '2025-08-23 21:31:40'),
(85, 11, 'transfert', 'chantier', 1, 'depot', 1, 10, 'valide', NULL, 4, 3, '2025-08-23 22:13:41'),
(86, 12, 'transfert', 'chantier', 1, 'depot', 1, 5, 'valide', NULL, 3, 3, '2025-08-23 22:20:59'),
(87, 12, 'transfert', 'chantier', 1, 'depot', 1, 5, 'valide', NULL, 4, 6, '2025-08-23 22:21:35'),
(88, 11, 'transfert', 'depot', 1, 'chantier', 2, 1, 'valide', NULL, 3, 3, '2025-08-23 22:22:17'),
(89, 11, 'transfert', 'chantier', 2, 'depot', 2, 1, 'valide', NULL, 3, 3, '2025-08-23 22:22:33'),
(90, 11, 'transfert', 'depot', 2, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-08-23 22:22:51'),
(91, 11, 'transfert', 'depot', 1, 'depot', 2, 1, 'valide', NULL, 3, 3, '2025-08-24 15:17:44'),
(92, 11, 'transfert', 'depot', 2, 'depot', 1, 1, 'valide', NULL, 3, 6, '2025-08-24 21:38:14'),
(93, 11, 'transfert', 'depot', 1, 'chantier', 1, 2, 'valide', NULL, 3, 4, '2025-08-24 21:51:31'),
(94, 2, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-08-25 21:24:24'),
(95, 2, 'transfert', 'depot', 1, 'depot', 2, 1, 'valide', NULL, 6, 8, '2025-08-25 21:25:27'),
(96, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 6, 4, '2025-08-25 21:25:52'),
(97, 2, 'transfert', 'depot', 2, 'depot', 1, 1, 'valide', NULL, 8, 6, '2025-08-25 21:27:52'),
(98, 2, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-08-25 21:28:20'),
(99, 11, 'transfert', 'chantier', 1, 'depot', 1, 3, 'valide', NULL, 4, 6, '2025-08-25 21:34:31'),
(100, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-08-26 18:44:46'),
(101, 11, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-08-28 22:33:40'),
(102, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-08-28 22:35:06'),
(103, 11, 'transfert', 'chantier', 1, 'depot', NULL, 1, 'annule', NULL, NULL, 3, '2025-08-28 22:49:02'),
(104, 2, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-08-28 22:49:18'),
(105, 2, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-08-28 22:52:20'),
(106, 11, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-08-28 22:52:21'),
(107, 11, 'transfert', 'depot', 1, 'chantier', 1, 1, 'valide', NULL, 3, 3, '2025-08-30 14:55:12'),
(108, 11, 'transfert', 'chantier', 1, 'depot', 1, 1, 'valide', NULL, 3, 3, '2025-08-30 14:56:34');

-- --------------------------------------------------------

--
-- Structure de la table `transferts_en_attente`
--

CREATE TABLE `transferts_en_attente` (
  `id` int(11) NOT NULL,
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
  `entreprise_id` int(11) NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `agence_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`id`, `nom`, `prenom`, `email`, `motDePasse`, `fonction`, `entreprise_id`, `photo`, `created_at`, `agence_id`) VALUES
(3, 'Rodrigues', 'Anthony', 'anthony-rodrigues31@hotmail.fr', '$2y$10$C4KMy.RRbOE.V4M7puAda.5gcTKDZVYZ4lo4eL0KDfjtoHO2NNnI.', 'administrateur', 1, '/uploads/photos/687ce56a7631d_anthony.jpg', '2025-07-17 18:37:12', NULL),
(4, 'Rodrigues', 'Ana', 'user@hotmail.fr', '$2y$10$WDIDL8RdOUYD0gGKRrMKkOjWOHB1OXcxH58ZrIEwAbrreTi8Y4fRW', 'chef', 1, NULL, '2025-07-17 20:28:19', 4),
(5, 'Boya', 'Stéphanie', 'bambina.31@hotmail.fr', '$2y$10$TzQr83yoAtF0YmQgh7n8qeHIL3z5C23/LPfS86VZkXZ.Nu/F9xRoS', 'chef', 1, NULL, '2025-07-17 20:29:00', 4),
(6, 'Rodrigues', 'Sam', 'anthonyrodrigues0512@gmail.com', '$2y$10$tSVxg90G0qj96s7beBd6tuSMQn9P69MWaivTwKfvypRPQIuspJKgC', 'depot', 1, NULL, '2025-07-17 20:29:17', 4),
(8, 'Bachar', 'Younes', 'user@test.com', '$2y$10$oSV7WwxVwMwPpw8.JnsRF.aiE915VUStRGeed/07BiFmHRJN/pyZO', 'depot', 1, NULL, '2025-08-19 19:36:28', 5),
(13, 'Jalran', 'Maxime', 'max@email.fr', '$2y$10$BvaahM5WDTrp5P.j9SiX..VAvwNNxG21yZRAeE5N.BofRHPYR8Sra', 'employe', 1, NULL, '2025-08-30 19:26:38', 4);

-- --------------------------------------------------------

--
-- Structure de la table `utilisateur_chantiers`
--

CREATE TABLE `utilisateur_chantiers` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `chantier_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateur_chantiers`
--

INSERT INTO `utilisateur_chantiers` (`id`, `utilisateur_id`, `chantier_id`) VALUES
(49, 4, 1),
(40, 5, 2);

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
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `depots`
--
ALTER TABLE `depots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `responsable_id` (`responsable_id`);

--
-- Index pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `utilisateur_id` (`utilisateur_id`);

--
-- Index pour la table `stock`
--
ALTER TABLE `stock`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `stock_chantiers`
--
ALTER TABLE `stock_chantiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chantier_id` (`chantier_id`),
  ADD KEY `stock_id` (`stock_id`);

--
-- Index pour la table `stock_depots`
--
ALTER TABLE `stock_depots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `depot_id` (`depot_id`),
  ADD KEY `stock_id` (`stock_id`);

--
-- Index pour la table `stock_documents`
--
ALTER TABLE `stock_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stock_path` (`stock_id`,`chemin_fichier`),
  ADD KEY `idx_stock_id_created_at` (`stock_id`,`created_at`),
  ADD KEY `idx_checksum` (`checksum_sha1`),
  ADD KEY `fk_stock_documents_user` (`uploaded_by`);

--
-- Index pour la table `stock_mouvements`
--
ALTER TABLE `stock_mouvements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `stock_id` (`stock_id`,`created_at`);

--
-- Index pour la table `transferts_en_attente`
--
ALTER TABLE `transferts_en_attente`
  ADD PRIMARY KEY (`id`),
  ADD KEY `article_id` (`article_id`),
  ADD KEY `demandeur_id` (`demandeur_id`);

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
  ADD KEY `chantier_id` (`chantier_id`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `agences`
--
ALTER TABLE `agences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT pour la table `chantiers`
--
ALTER TABLE `chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT pour la table `depots`
--
ALTER TABLE `depots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=254;

--
-- AUTO_INCREMENT pour la table `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT pour la table `stock_chantiers`
--
ALTER TABLE `stock_chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT pour la table `stock_depots`
--
ALTER TABLE `stock_depots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT pour la table `stock_documents`
--
ALTER TABLE `stock_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT pour la table `stock_mouvements`
--
ALTER TABLE `stock_mouvements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT pour la table `transferts_en_attente`
--
ALTER TABLE `transferts_en_attente`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=264;

--
-- AUTO_INCREMENT pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT pour la table `utilisateur_chantiers`
--
ALTER TABLE `utilisateur_chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `depots`
--
ALTER TABLE `depots`
  ADD CONSTRAINT `depots_ibfk_1` FOREIGN KEY (`responsable_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `stock_chantiers`
--
ALTER TABLE `stock_chantiers`
  ADD CONSTRAINT `stock_chantiers_ibfk_1` FOREIGN KEY (`chantier_id`) REFERENCES `chantiers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_chantiers_ibfk_2` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `stock_depots`
--
ALTER TABLE `stock_depots`
  ADD CONSTRAINT `stock_depots_ibfk_1` FOREIGN KEY (`depot_id`) REFERENCES `depots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_depots_ibfk_2` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `stock_documents`
--
ALTER TABLE `stock_documents`
  ADD CONSTRAINT `fk_stock_documents_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stock_documents_user` FOREIGN KEY (`uploaded_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `stock_mouvements`
--
ALTER TABLE `stock_mouvements`
  ADD CONSTRAINT `fk_sm_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `transferts_en_attente`
--
ALTER TABLE `transferts_en_attente`
  ADD CONSTRAINT `transferts_en_attente_ibfk_1` FOREIGN KEY (`article_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transferts_en_attente_ibfk_2` FOREIGN KEY (`demandeur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD CONSTRAINT `fk_utilisateurs_agence` FOREIGN KEY (`agence_id`) REFERENCES `agences` (`id`) ON DELETE SET NULL;

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
