<?php
include '../includes/session_fix.php';
require_roles(['Registrar']);
?>
<?php
$pageTitle = 'Formation Management';
$activeNav = 'formation';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
            <h1 class="page-title"><i class="fas fa-chart-line"></i> Formation Management</h1>
            <p style="color: var(--text-secondary); margin-bottom: 2rem;">Select a formation category to manage seminarian evaluations.</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                <a href="academic.php" class="card" style="padding: 2rem; margin: 0; text-decoration: none; text-align: center; transition: var(--transition);">
                    <span style="display: block; font-size: 2.5rem; margin-bottom: 1rem;">🎓</span>
                    <h3 style="color: #1e293b;">Academic</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Grades and scholastic performance</p>
                </a>
                <a href="spiritual.php" class="card" style="padding: 2rem; margin: 0; text-decoration: none; text-align: center; transition: var(--transition);">
                    <span style="display: block; font-size: 2.5rem; margin-bottom: 1rem;">🕯️</span>
                    <h3 style="color: #1e293b;">Spiritual</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Prayer life and spiritual growth</p>
                </a>
                <a href="human.php" class="card" style="padding: 2rem; margin: 0; text-decoration: none; text-align: center; transition: var(--transition);">
                    <span style="display: block; font-size: 2.5rem; margin-bottom: 1rem;">🤝</span>
                    <h3 style="color: #1e293b;">Human</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Character and community life</p>
                </a>
                <a href="pastoral.php" class="card" style="padding: 2rem; margin: 0; text-decoration: none; text-align: center; transition: var(--transition);">
                    <span style="display: block; font-size: 2.5rem; margin-bottom: 1rem;">⛪</span>
                    <h3 style="color: #1e293b;">Pastoral</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Ministry and service activities</p>
                </a>
            </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
