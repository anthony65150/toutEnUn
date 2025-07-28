-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : dim. 27 juil. 2025 à 17:06
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
(1, 'Chantier Bouchait', '', NULL, NULL, 4, '2025-07-17 20:27:49'),
(2, 'Chantier Coma', '', NULL, NULL, 5, '2025-07-17 20:27:49'),
(6, 'Chantier Montrejeau', '', '2025-07-28', '2025-11-26', 4, '2025-07-25 13:04:13'),
(14, 'Chantier Piscine', '', '2025-07-14', '2026-06-22', 5, '2025-07-27 14:19:05');

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
(1, 'Dépôt Montrejeau', 6, '2025-07-17 20:31:20');

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
(134, 3, '✅ Le transfert de 1 x Piqueur a été validé par l\'administrateur.', 0, '2025-07-27 14:59:35');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `depot_id` int(11) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `document` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock`
--

INSERT INTO `stock` (`id`, `nom`, `quantite_totale`, `quantite_disponible`, `categorie`, `sous_categorie`, `created_at`, `depot_id`, `photo`, `document`) VALUES
(2, 'banche 2m75', 12, 12, 'Banches', 'Manuportables', '2025-07-17 20:31:41', NULL, NULL, NULL),
(3, 'plateau 3m', 100, 100, 'echafaudage', '3m', '2025-07-20 19:30:14', NULL, NULL, NULL),
(4, 'Piqueur', 23, 23, 'Electroportatif', 'Piquage', '2025-07-27 14:58:37', NULL, NULL, NULL);

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
(29, 1, 3, 0, '2025-07-25 19:48:06'),
(30, 1, 2, 0, '2025-07-25 20:33:23'),
(31, 6, 2, 0, '2025-07-25 21:03:25'),
(32, 2, 3, 0, '2025-07-27 14:19:49'),
(33, 14, 3, 0, '2025-07-27 14:19:55'),
(34, 1, 4, 0, '2025-07-27 14:59:21');

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
(2, 1, 2, 12),
(3, 1, 3, 100),
(4, 1, 4, 23);

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
  `fonction` enum('administrateur','depot','chef') DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`id`, `nom`, `prenom`, `email`, `motDePasse`, `fonction`, `photo`, `created_at`) VALUES
(3, 'Rodrigues', 'Anthony', 'anthony-rodrigues31@hotmail.fr', '$2y$10$C4KMy.RRbOE.V4M7puAda.5gcTKDZVYZ4lo4eL0KDfjtoHO2NNnI.', 'administrateur', '/uploads/photos/687ce56a7631d_anthony.jpg', '2025-07-17 18:37:12'),
(4, 'Rodrigues', 'Ana', 'user@hotmail.fr', '$2y$10$WDIDL8RdOUYD0gGKRrMKkOjWOHB1OXcxH58ZrIEwAbrreTi8Y4fRW', 'chef', NULL, '2025-07-17 20:28:19'),
(5, 'Boya', 'Stéphanie', 'bambina.31@hotmail.fr', '$2y$10$TzQr83yoAtF0YmQgh7n8qeHIL3z5C23/LPfS86VZkXZ.Nu/F9xRoS', 'chef', NULL, '2025-07-17 20:29:00'),
(6, 'Rodrigues', 'Sam', 'anthonyrodrigues0512@gmail.com', '$2y$10$tSVxg90G0qj96s7beBd6tuSMQn9P69MWaivTwKfvypRPQIuspJKgC', 'depot', NULL, '2025-07-17 20:29:17');

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
(36, 4, 1),
(8, 4, 6),
(4, 5, 2),
(37, 5, 14);

--
-- Index pour les tables déchargées
--

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
  ADD UNIQUE KEY `email` (`email`);

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
-- AUTO_INCREMENT pour la table `chantiers`
--
ALTER TABLE `chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT pour la table `depots`
--
ALTER TABLE `depots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=135;

--
-- AUTO_INCREMENT pour la table `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `stock_chantiers`
--
ALTER TABLE `stock_chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT pour la table `stock_depots`
--
ALTER TABLE `stock_depots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `transferts_en_attente`
--
ALTER TABLE `transferts_en_attente`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=145;

--
-- AUTO_INCREMENT pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT pour la table `utilisateur_chantiers`
--
ALTER TABLE `utilisateur_chantiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

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
-- Contraintes pour la table `transferts_en_attente`
--
ALTER TABLE `transferts_en_attente`
  ADD CONSTRAINT `transferts_en_attente_ibfk_1` FOREIGN KEY (`article_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transferts_en_attente_ibfk_2` FOREIGN KEY (`demandeur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

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
