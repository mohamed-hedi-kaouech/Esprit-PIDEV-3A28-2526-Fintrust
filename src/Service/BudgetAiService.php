<?php
namespace App\Service;
use App\Repository\CategorieRepository;
use App\Repository\ItemRepository;
class BudgetAiService
{
    public function __construct(
        private CategorieRepository $categorieRepository,
        private ItemRepository $itemRepository,
    ) {}
    public function answer(string $question): string
    {
        $q = mb_strtolower(trim($question));
        $data = $this->loadData();
        if (empty($data['categories'])) {
            return "Aucune donnee budgetaire trouvee. Creez d abord des categories et des depenses.";
        }
        if ($this->has($q, ['budget total','budget global','budget alloue','combien de budget'])) {
            return sprintf("Le budget total alloue est de **%s TND** reparti sur %d categorie(s).",
                number_format($data['totalBudget'],3,'.',' '), count($data['categories']));
        }
        if ($this->has($q, ['depense','depenses','combien depense','total depense','montant depense'])) {
            $pct = $data['totalBudget']>0 ? round($data['totalSpent']/$data['totalBudget']*100,1) : 0;
            return sprintf("Vous avez depense **%s TND** sur %s TND, soit **%s%%** du budget.",
                number_format($data['totalSpent'],3,'.',' '),
                number_format($data['totalBudget'],3,'.',' '), $pct);
        }
        if ($this->has($q, ['restant','reste','disponible','solde','combien reste'])) {
            $pct = $data['totalBudget']>0 ? round($data['totalSpent']/$data['totalBudget']*100,1) : 0;
            $etat = $data['totalRemaining']>=0 ? "positif" : "DEPASSE";
            return sprintf("Il vous reste **%s TND** (%s). Budget utilise : %s%%.",
                number_format($data['totalRemaining'],3,'.',' '), $etat, $pct);
        }
        if ($this->has($q, ['categorie','categories','liste','quelles categories'])) {
            $lines = [];
            foreach ($data['categories'] as $cat) {
                $lines[] = sprintf("- **%s** : budget %s | depense %s | restant %s TND",
                    $cat['nom'],
                    number_format($cat['budget'],3,'.',' '),
                    number_format($cat['spent'],3,'.',' '),
                    number_format($cat['remaining'],3,'.',' '));
            }
            return "Vos categories :\n".implode("\n",$lines);
        }
        if ($this->has($q, ['plus couteuse','plus chere','plus depensee','categorie max','categorie la plus'])) {
            $top = $this->topCat($data['categories']);
            return sprintf("La categorie la plus couteuse est **%s** avec **%s TND** depenses (%s%% du budget).",
                $top['nom'], number_format($top['spent'],3,'.',' '), $top['pct']);
        }
        if ($this->has($q, ['depassement','depasse','over budget','budget depasse'])) {
            $over = array_filter($data['categories'], fn($c)=>$c['remaining']<0);
            if (empty($over)) return "Aucun depassement detecte. Toutes les categories sont dans les limites.";
            $lines = [];
            foreach ($over as $cat) {
                $lines[] = sprintf("- **%s** : depassement de %s TND", $cat['nom'], number_format(abs($cat['remaining']),3,'.',' '));
            }
            return "Categories en depassement :\n".implode("\n",$lines);
        }
        if ($this->has($q, ['alerte','alertes','risque','danger','critique'])) {
            $alerts = array_filter($data['categories'], fn($c)=>$c['pct']>=80);
            if (empty($alerts)) return "Aucune alerte. Toutes les categories sont sous 80% d utilisation.";
            $lines = [];
            foreach ($alerts as $cat) {
                $lines[] = sprintf("- **%s** : %s%% utilise", $cat['nom'], $cat['pct']);
            }
            return "Categories a surveiller :\n".implode("\n",$lines);
        }
        if ($this->has($q, ['conseil','optimiser','ameliorer','suggestion','recommandation'])) {
            return $this->advice($data);
        }
        if ($this->has($q, ['economie','economies','economiser','reduire'])) {
            return $this->savings($data);
        }
        if ($this->has($q, ['repartition','distribution','proportion'])) {
            return $this->distribution($data);
        }
        if ($this->has($q, ['items','nombre de depenses','combien items'])) {
            $total = array_sum(array_column($data['categories'],'itemCount'));
            $lines = [];
            foreach ($data['categories'] as $cat) {
                $lines[] = sprintf("- **%s** : %d depense(s)", $cat['nom'], $cat['itemCount']);
            }
            return sprintf("Total : **%d depense(s)** :\n%s", $total, implode("\n",$lines));
        }
        if ($this->has($q, ['aide','help','que peux tu','fonctionnalites'])) {
            return "Je suis votre assistant budgetaire FinTrust. Je peux :\n"
                ."- Afficher le **budget total** et le **montant restant**\n"
                ."- Lister vos **categories** et depenses\n"
                ."- Detecter les **depassements**\n"
                ."- Identifier la **categorie la plus couteuse**\n"
                ."- Signaler les **alertes** et risques\n"
                ."- Donner des **conseils d optimisation**\n"
                ."- Suggerer des **economies**\n"
                ."- Analyser la **repartition** du budget\n\n"
                ."Posez-moi une question !";
        }
        return $this->fallback($data, $q);
    }
    private function loadData(): array
    {
        $categories = $this->categorieRepository->findAll();
        $totalBudget = 0.0; $totalSpent = 0.0; $cats = [];
        foreach ($categories as $cat) {
            $items = $this->itemRepository->findBy(['categorie'=>$cat]);
            $spent = array_sum(array_map(fn($i)=>$i->getMontant(), $items));
            $budget = $cat->getBudgetPrevu();
            $pct = $budget>0 ? round($spent/$budget*100,1) : 0;
            $totalBudget += $budget; $totalSpent += $spent;
            $cats[] = ['nom'=>$cat->getNomCategorie(),'budget'=>$budget,'spent'=>$spent,
                'remaining'=>$budget-$spent,'pct'=>$pct,'seuil'=>$cat->getSeuilAlerte(),'itemCount'=>count($items)];
        }
        usort($cats, fn($a,$b)=>$b['spent']<=>$a['spent']);
        return ['totalBudget'=>$totalBudget,'totalSpent'=>$totalSpent,'totalRemaining'=>$totalBudget-$totalSpent,'categories'=>$cats];
    }
    private function has(string $q, array $kw): bool
    {
        foreach ($kw as $k) { if (str_contains($q,$k)) return true; }
        return false;
    }
    private function topCat(array $cats): array
    {
        return array_reduce($cats, fn($c,$i)=>($c===null||$i['spent']>$c['spent'])?$i:$c, null)??$cats[0];
    }
    private function advice(array $data): string
    {
        $lines = [];
        foreach ($data['categories'] as $cat) {
            if ($cat['pct']>=90) $lines[] = "- **{$cat['nom']}** : budget presque epuise ({$cat['pct']}%). Evitez de nouvelles depenses.";
            elseif ($cat['pct']>=70) $lines[] = "- **{$cat['nom']}** : {$cat['pct']}% utilise. Ralentissez les depenses.";
            elseif ($cat['pct']<20 && $cat['budget']>0) $lines[] = "- **{$cat['nom']}** : seulement {$cat['pct']}% utilise. Vous pouvez redistribuer vers d autres categories.";
        }
        return empty($lines) ? "Votre budget est bien equilibre. Continuez ainsi !" : "Conseils :\n".implode("\n",$lines);
    }
    private function savings(array $data): string
    {
        $top = array_slice($data['categories'],0,3);
        $lines = [];
        foreach ($top as $cat) {
            if ($cat['spent']>0) {
                $s = round($cat['spent']*0.1,3);
                $lines[] = sprintf("- Reduire **%s** de 10%% = economie de **%s TND**", $cat['nom'], number_format($s,3,'.',' '));
            }
        }
        if (empty($lines)) return "Aucune depense enregistree pour calculer des economies.";
        $total = array_sum(array_map(fn($c)=>$c['spent']*0.1, $top));
        return "En reduisant de 10%% vos 3 categories les plus couteuses, economie potentielle : **".number_format($total,3,'.',' ')." TND** :\n".implode("\n",$lines);
    }
    private function distribution(array $data): string
    {
        if ($data['totalBudget']<=0) return "Aucun budget defini.";
        $lines = [];
        foreach ($data['categories'] as $cat) {
            $share = round($cat['budget']/$data['totalBudget']*100,1);
            $bar = str_repeat("█",(int)($share/5)).str_repeat("░",20-(int)($share/5));
            $lines[] = "**{$cat['nom']}** {$bar} {$share}%";
        }
        return "Repartition du budget :\n".implode("\n",$lines);
    }
    private function fallback(array $data, string $q): string
    {
        // Si la question contient un nom de categorie connue → repondre avec ses details
        foreach ($data['categories'] as $cat) {
            if (str_contains(mb_strtolower($q), mb_strtolower($cat['nom']))) {
                return sprintf("Categorie **%s** :\n- Budget : %s TND\n- Depense : %s TND\n- Restant : %s TND\n- Utilisation : %s%%\n- Depenses enregistrees : %d",
                    $cat['nom'],number_format($cat['budget'],3,'.',' '),number_format($cat['spent'],3,'.',' '),
                    number_format($cat['remaining'],3,'.',' '),$cat['pct'],$cat['itemCount']);
            }
        }

        // Question hors sujet → refus clair
        return "Je suis uniquement specialise dans la **gestion de budget FinTrust**.\n"
            . "Je ne peux pas repondre a cette question.\n\n"
            . "Voici ce que je sais faire :\n"
            . "- Budget total / montant restant\n"
            . "- Detail des categories et depenses\n"
            . "- Detection des depassements\n"
            . "- Alertes et risques budgetaires\n"
            . "- Conseils d optimisation et economies\n\n"
            . "Tapez **aide** pour plus de details.";
    }
}