<?php

namespace App\Entity;

use App\Repository\AiSymbolReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AiSymbolReportRepository::class)]
#[ORM\Table(name: 'ai_symbol_report')]
#[ORM\Index(name: 'IDX_AI_SYMBOL_REPORT_SYMBOL_CREATED', columns: ['symbol', 'created_at'])]
#[ORM\Index(name: 'IDX_AI_SYMBOL_REPORT_REPORT_DATE', columns: ['report_date'])]
#[ORM\Index(name: 'IDX_AI_SYMBOL_REPORT_SCOPE_CREATED', columns: ['report_scope', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class AiSymbolReport
{
    public const SCOPE_TRACKED = 'tracked';
    public const SCOPE_OPPORTUNITY = 'opportunity';

    public const TREND_NEGATIVE = 'negatif';
    public const TREND_NEUTRAL = 'notr';
    public const TREND_POSITIVE = 'pozitif';

    public const DECISION_FOLLOW = 'takip_et';
    public const DECISION_WAIT = 'bekle';
    public const DECISION_RISKY = 'riskli';

    public const ANALYSIS_SUCCESS = 'success';
    public const ANALYSIS_MOCK = 'mock';
    public const ANALYSIS_FALLBACK_JSON = 'fallback_json';
    public const ANALYSIS_FALLBACK_ERROR = 'fallback_error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $symbol;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $reportDate;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $score = 0;

    #[ORM\Column(length: 20)]
    private string $trendLabel = self::TREND_NEUTRAL;

    #[ORM\Column(length: 30)]
    private string $decisionLabel = self::DECISION_WAIT;

    #[ORM\Column(length: 20)]
    private string $confidence = 'dusuk';

    #[ORM\Column(length: 30)]
    private string $analysisStatus = self::ANALYSIS_SUCCESS;

    #[ORM\Column(length: 40)]
    private string $historyStatus = 'missing_history';

    #[ORM\Column(length: 20, options: ['default' => self::SCOPE_TRACKED])]
    private string $reportScope = self::SCOPE_TRACKED;

    #[ORM\Column(nullable: true)]
    private ?float $price = null;

    #[ORM\Column(nullable: true)]
    private ?float $dailyChangePercent = null;

    #[ORM\Column(length: 40)]
    private string $dataStatus = 'missing_price';

    #[ORM\Column(options: ['default' => false])]
    private bool $isPriceStale = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPortfolio = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isWatchlist = false;

    #[ORM\Column(type: Types::TEXT)]
    private string $dailyComment = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $shortTerm = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $mediumTerm = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $longTerm = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $kapImpact = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $riskSummary = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawResponse = null;

    #[ORM\Column(type: Types::JSON)]
    private array $priceSnapshot = [];

    #[ORM\Column(type: Types::JSON)]
    private array $technicalSnapshot = [];

    #[ORM\Column(type: Types::JSON)]
    private array $kapNewsIds = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->reportDate = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = strtoupper(trim($symbol));
        return $this;
    }

    public function getReportDate(): \DateTimeImmutable
    {
        return $this->reportDate;
    }

    public function setReportDate(\DateTimeImmutable $reportDate): static
    {
        $this->reportDate = $reportDate;
        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = max(0, min(100, $score));
        return $this;
    }

    public function getTrendLabel(): string
    {
        return $this->trendLabel;
    }

    public function setTrendLabel(string $trendLabel): static
    {
        $this->trendLabel = $this->oneOf($trendLabel, [
            self::TREND_NEGATIVE,
            self::TREND_NEUTRAL,
            self::TREND_POSITIVE,
        ], self::TREND_NEUTRAL);

        return $this;
    }

    public function getDecisionLabel(): string
    {
        return $this->decisionLabel;
    }

    public function setDecisionLabel(string $decisionLabel): static
    {
        $this->decisionLabel = $this->oneOf($decisionLabel, [
            self::DECISION_FOLLOW,
            self::DECISION_WAIT,
            self::DECISION_RISKY,
        ], self::DECISION_WAIT);

        return $this;
    }

    public function getConfidence(): string
    {
        return $this->confidence;
    }

    public function setConfidence(string $confidence): static
    {
        $this->confidence = $this->oneOf($confidence, ['dusuk', 'orta', 'yuksek'], 'dusuk');
        return $this;
    }

    public function getAnalysisStatus(): string
    {
        return $this->analysisStatus;
    }

    public function setAnalysisStatus(string $analysisStatus): static
    {
        $this->analysisStatus = $this->oneOf($analysisStatus, [
            self::ANALYSIS_SUCCESS,
            self::ANALYSIS_MOCK,
            self::ANALYSIS_FALLBACK_JSON,
            self::ANALYSIS_FALLBACK_ERROR,
        ], self::ANALYSIS_FALLBACK_ERROR);

        return $this;
    }

    public function getHistoryStatus(): string
    {
        return $this->historyStatus;
    }

    public function setHistoryStatus(string $historyStatus): static
    {
        $this->historyStatus = substr(trim($historyStatus), 0, 40) ?: 'missing_history';
        return $this;
    }

    public function getReportScope(): string
    {
        return $this->reportScope;
    }

    public function setReportScope(string $reportScope): static
    {
        $this->reportScope = in_array($reportScope, [self::SCOPE_TRACKED, self::SCOPE_OPPORTUNITY], true)
            ? $reportScope
            : self::SCOPE_TRACKED;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(?float $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getDailyChangePercent(): ?float
    {
        return $this->dailyChangePercent;
    }

    public function setDailyChangePercent(?float $dailyChangePercent): static
    {
        $this->dailyChangePercent = $dailyChangePercent;
        return $this;
    }

    public function getDataStatus(): string
    {
        return $this->dataStatus;
    }

    public function setDataStatus(string $dataStatus): static
    {
        $this->dataStatus = substr($dataStatus, 0, 40);
        return $this;
    }

    public function isPriceStale(): bool
    {
        return $this->isPriceStale;
    }

    public function setIsPriceStale(bool $isPriceStale): static
    {
        $this->isPriceStale = $isPriceStale;
        return $this;
    }

    public function isPortfolio(): bool
    {
        return $this->isPortfolio;
    }

    public function setIsPortfolio(bool $isPortfolio): static
    {
        $this->isPortfolio = $isPortfolio;
        return $this;
    }

    public function isWatchlist(): bool
    {
        return $this->isWatchlist;
    }

    public function setIsWatchlist(bool $isWatchlist): static
    {
        $this->isWatchlist = $isWatchlist;
        return $this;
    }

    public function getDailyComment(): string
    {
        return $this->dailyComment;
    }

    public function setDailyComment(string $dailyComment): static
    {
        $this->dailyComment = $dailyComment;
        return $this;
    }

    public function getShortTerm(): string
    {
        return $this->shortTerm;
    }

    public function setShortTerm(string $shortTerm): static
    {
        $this->shortTerm = $shortTerm;
        return $this;
    }

    public function getMediumTerm(): string
    {
        return $this->mediumTerm;
    }

    public function setMediumTerm(string $mediumTerm): static
    {
        $this->mediumTerm = $mediumTerm;
        return $this;
    }

    public function getLongTerm(): string
    {
        return $this->longTerm;
    }

    public function setLongTerm(string $longTerm): static
    {
        $this->longTerm = $longTerm;
        return $this;
    }

    public function getKapImpact(): string
    {
        return $this->kapImpact;
    }

    public function setKapImpact(string $kapImpact): static
    {
        $this->kapImpact = $kapImpact;
        return $this;
    }

    public function getRiskSummary(): string
    {
        return $this->riskSummary;
    }

    public function setRiskSummary(string $riskSummary): static
    {
        $this->riskSummary = $riskSummary;
        return $this;
    }

    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }

    public function setRawResponse(?string $rawResponse): static
    {
        $this->rawResponse = $rawResponse;
        return $this;
    }

    public function getPriceSnapshot(): array
    {
        return $this->priceSnapshot;
    }

    public function setPriceSnapshot(array $priceSnapshot): static
    {
        $this->priceSnapshot = $priceSnapshot;
        return $this;
    }

    public function getTechnicalSnapshot(): array
    {
        return $this->technicalSnapshot;
    }

    public function setTechnicalSnapshot(array $technicalSnapshot): static
    {
        $this->technicalSnapshot = $technicalSnapshot;
        return $this;
    }

    public function getKapNewsIds(): array
    {
        return $this->kapNewsIds;
    }

    public function setKapNewsIds(array $kapNewsIds): static
    {
        $this->kapNewsIds = array_values($kapNewsIds);
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function trendLabelText(): string
    {
        return match ($this->trendLabel) {
            self::TREND_POSITIVE => 'Pozitif',
            self::TREND_NEGATIVE => 'Negatif',
            default => 'Notr',
        };
    }

    public function decisionLabelText(): string
    {
        return match ($this->decisionLabel) {
            self::DECISION_FOLLOW => 'Takip et',
            self::DECISION_RISKY => 'Riskli',
            default => 'Bekle',
        };
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @param string[] $allowed
     */
    private function oneOf(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
