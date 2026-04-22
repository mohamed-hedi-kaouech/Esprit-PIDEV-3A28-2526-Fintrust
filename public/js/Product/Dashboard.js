const charts = {};

function destroyChart(id) {
    if (charts[id]) {
        charts[id].destroy();
        delete charts[id];
    }
}

function showToast(message, duration = 3200) {
    const toast = document.getElementById('toast');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.classList.add('show');

    window.setTimeout(() => {
        toast.classList.remove('show');
    }, duration);
}

const FT_COLORS = {
    blue: '#2563EB',
    blueSoft: '#3B82F6',
    blueLight: 'rgba(59, 130, 246, 0.18)',
    slate: '#0F172A',
    slateMuted: '#64748B',
    grid: 'rgba(148, 163, 184, 0.16)',
    success: '#22C55E',
    successSoft: 'rgba(34, 197, 94, 0.18)',
    danger: '#EF4444',
    dangerSoft: 'rgba(239, 68, 68, 0.16)',
    warning: '#F59E0B',
    warningSoft: 'rgba(245, 158, 11, 0.18)',
    violet: '#8B5CF6',
    violetSoft: 'rgba(139, 92, 246, 0.18)',
};

const STATUS_PALETTE = [
    FT_COLORS.success,
    FT_COLORS.warning,
    FT_COLORS.danger,
    '#94A3B8',
];

const CATEGORY_PALETTE = [
    '#2563EB',
    '#3B82F6',
    '#60A5FA',
    '#8B5CF6',
    '#22C55E',
    '#F59E0B',
    '#0EA5E9',
];

const TYPE_PALETTE = [
    '#8B5CF6',
    '#A855F7',
    '#C084FC',
    '#3B82F6',
];

Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.color = FT_COLORS.slateMuted;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.pointStyle = 'circle';

function chartGradient(ctx, area, fromColor, toColor) {
    const gradient = ctx.createLinearGradient(0, area.bottom, 0, area.top);
    gradient.addColorStop(0, toColor);
    gradient.addColorStop(1, fromColor);
    return gradient;
}

function baseChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
            duration: 900,
            easing: 'easeOutQuart',
        },
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 18,
                    font: {
                        size: 12,
                        weight: 600,
                    },
                },
            },
            tooltip: {
                backgroundColor: '#0F172A',
                titleFont: {
                    size: 13,
                    weight: 700,
                },
                bodyFont: {
                    size: 12,
                    weight: 500,
                },
                padding: 12,
                cornerRadius: 14,
                displayColors: true,
            },
        },
    };
}

function baseScaleOptions() {
    return {
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: FT_COLORS.slateMuted,
                font: {
                    size: 11,
                    weight: 600,
                },
            },
            border: {
                display: false,
            },
        },
        y: {
            beginAtZero: true,
            grid: {
                color: FT_COLORS.grid,
                drawBorder: false,
            },
            ticks: {
                color: FT_COLORS.slateMuted,
                font: {
                    size: 11,
                    weight: 600,
                },
                padding: 8,
            },
            border: {
                display: false,
            },
        },
    };
}

function makeDoughnut(canvasId, labels, data) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas) {
        return;
    }

    charts[canvasId] = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: STATUS_PALETTE,
                borderWidth: 0,
                hoverOffset: 10,
                cutout: '70%',
                spacing: 3,
            }],
        },
        options: {
            ...baseChartOptions(),
        },
    });
}

function makeBar(canvasId, labels, datasets, extraOptions = {}) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas) {
        return;
    }

    charts[canvasId] = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels,
            datasets,
        },
        options: {
            ...baseChartOptions(),
            scales: baseScaleOptions(),
            ...extraOptions,
        },
    });
}

function makeLine(canvasId, labels, datasets) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas) {
        return;
    }

    charts[canvasId] = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels,
            datasets,
        },
        options: {
            ...baseChartOptions(),
            scales: baseScaleOptions(),
        },
    });
}

function formatCurrency(value) {
    const number = Number(value || 0);
    return number.toFixed(2);
}

function fillKPIs(data) {
    const totalProducts = data.totalProducts ?? 0;
    const totalSubs = data.totalSubs ?? 0;
    const avgPrice = data.avgPrice ?? 0;
    const totalRevenue = data.totalRevenue ?? 0;

    document.getElementById('lblTotalProducts').textContent = totalProducts;
    document.getElementById('lblAvgPrice').textContent = formatCurrency(avgPrice);
    document.getElementById('lblTopCategory').textContent = data.topCategory || '-';
    document.getElementById('lblTopCategoryCount').textContent = `${data.topCategoryCount ?? 0} produit${(data.topCategoryCount ?? 0) > 1 ? 's' : ''}`;
    document.getElementById('lblPriceRange').textContent = `${formatCurrency(data.minPrice)} -> ${formatCurrency(data.maxPrice)}`;
    document.getElementById('lblNewestProduct').textContent = data.newestProduct || '-';
    document.getElementById('lblNewestDate').textContent = data.newestDate || '-';

    document.getElementById('lblTotalSubs').textContent = totalSubs;
    document.getElementById('lblActiveSubs').textContent = data.activeSubs ?? 0;
    document.getElementById('lblSuspendedSubs').textContent = data.suspendedSubs ?? 0;
    document.getElementById('lblClosedSubs').textContent = data.closedSubs ?? 0;
    document.getElementById('lblActiveRate').textContent = totalSubs ? `${((data.activeSubs / totalSubs) * 100).toFixed(1)}% du total` : '0.0% du total';
    document.getElementById('lblSuspendedRate').textContent = totalSubs ? `${((data.suspendedSubs / totalSubs) * 100).toFixed(1)}% du total` : '0.0% du total';
    document.getElementById('lblClosedRate').textContent = totalSubs ? `${((data.closedSubs / totalSubs) * 100).toFixed(1)}% du total` : '0.0% du total';
    document.getElementById('lblTotalRevenue').textContent = `${formatCurrency(totalRevenue)} DT`;

    const heroRevenue = document.getElementById('lblTotalRevenueHero');
    const heroProducts = document.getElementById('lblHeroProducts');
    const heroSubs = document.getElementById('lblHeroSubs');

    if (heroRevenue) {
        heroRevenue.textContent = `${formatCurrency(totalRevenue)} DT`;
    }
    if (heroProducts) {
        heroProducts.textContent = totalProducts;
    }
    if (heroSubs) {
        heroSubs.textContent = totalSubs;
    }
}

function buildCharts(data) {
    makeDoughnut(
        'chartSubsByStatus',
        ['Actifs', 'Suspendus', 'Fermes', 'Brouillons'],
        [data.activeSubs || 0, data.suspendedSubs || 0, data.closedSubs || 0, data.draftSubs || 0]
    );

    const categories = Object.keys(data.productsByCategory || {});
    makeBar('chartProductsByCategory', categories, [{
        label: 'Produits',
        data: categories.map((key) => data.productsByCategory[key]),
        backgroundColor: CATEGORY_PALETTE.slice(0, Math.max(categories.length, 1)),
        borderRadius: 12,
        borderSkipped: false,
        maxBarThickness: 54,
    }]);

    const types = Object.keys(data.subsByType || {});
    makeBar('chartSubsByType', types, [{
        label: 'Abonnements',
        data: types.map((key) => data.subsByType[key]),
        backgroundColor: TYPE_PALETTE.slice(0, Math.max(types.length, 1)),
        borderRadius: 12,
        borderSkipped: false,
        maxBarThickness: 54,
    }]);

    const months = Object.keys(data.subsOverTime || {});
    makeLine('chartSubsOverTime', months, [{
        label: 'Abonnements',
        data: months.map((key) => data.subsOverTime[key]),
        borderColor: FT_COLORS.blue,
        backgroundColor(context) {
            const chart = context.chart;
            const { ctx, chartArea } = chart;
            if (!chartArea) {
                return FT_COLORS.blueLight;
            }

            return chartGradient(ctx, chartArea, 'rgba(59, 130, 246, 0.28)', 'rgba(59, 130, 246, 0.02)');
        },
        fill: true,
        tension: 0.4,
        pointRadius: 4,
        pointHoverRadius: 6,
        pointBackgroundColor: '#ffffff',
        pointBorderWidth: 2,
        pointBorderColor: FT_COLORS.blue,
    }]);

    const revenueCategories = Object.keys(data.revenueByCategory || {});
    makeBar('chartRevenueByCategory', revenueCategories, [
        {
            label: 'Revenu estime (DT)',
            data: revenueCategories.map((key) => data.revenueByCategory[key].revenue),
            backgroundColor: 'rgba(37, 99, 235, 0.78)',
            borderRadius: 12,
            borderSkipped: false,
            yAxisID: 'y',
            maxBarThickness: 56,
        },
        {
            label: 'Abonnements actifs',
            data: revenueCategories.map((key) => data.revenueByCategory[key].subs),
            backgroundColor: 'rgba(139, 92, 246, 0.6)',
            borderRadius: 12,
            borderSkipped: false,
            yAxisID: 'y1',
            maxBarThickness: 56,
        },
    ], {
        scales: {
            ...baseScaleOptions(),
            y: {
                ...baseScaleOptions().y,
                position: 'left',
                title: {
                    display: true,
                    text: 'DT',
                    color: FT_COLORS.slateMuted,
                    font: {
                        size: 11,
                        weight: 700,
                    },
                },
            },
            y1: {
                ...baseScaleOptions().y,
                position: 'right',
                grid: {
                    display: false,
                },
                title: {
                    display: true,
                    text: 'Abonnements',
                    color: FT_COLORS.slateMuted,
                    font: {
                        size: 11,
                        weight: 700,
                    },
                },
            },
        },
    });
}

async function loadDashboard() {
    const button = document.getElementById('btnRefresh');

    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="bi bi-arrow-repeat"></i> Chargement...';
    }

    try {
        const response = await fetch('/admin/dashboard/data');
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();
        fillKPIs(data);
        buildCharts(data);

        const now = new Date().toLocaleString('fr-FR');
        const refreshLabel = document.getElementById('lblLastRefresh');
        if (refreshLabel) {
            refreshLabel.textContent = `Derniere actualisation : ${now}`;
        }

        showToast('Dashboard actualise avec succes');
    } catch (error) {
        console.error(error);
        showToast('Erreur lors du chargement des donnees', 4200);
    } finally {
        if (button) {
            button.disabled = false;
            button.innerHTML = '<i class="bi bi-arrow-repeat"></i> Actualiser';
        }
    }
}

async function sendEmailReport() {
    showToast('Envoi du rapport en cours...', 1800);

    try {
        const response = await fetch('/admin/dashboard/send-report', {
            method: 'POST',
        });
        const data = await response.json();

        if (data.success) {
            showToast('Rapport envoye par email');
            return;
        }

        showToast('Erreur lors de l envoi du rapport', 4200);
    } catch (error) {
        console.error(error);
        showToast('Impossible d envoyer le rapport', 4200);
    }
}

loadDashboard();
